<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\ApplyVerifiedDobAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Request;
use App\Models\User;
use App\Notifications\AgeVerificationRequestApprovedNotification;
use App\Notifications\AgeVerificationRequestRejectedNotification;
use Illuminate\Support\Facades\DB;

// TT-4.11c/SCRUM-304: applies the actual side effect on accept, mirroring
// RespondToDobChangeRequestAction's own "lock the request, re-check pending status inside the
// lock, apply on accept" shape.
//
// Authorization is NOT re-checked here -- RequestService::respondToRequest() already runs
// EnsureUserCanRespondToRequestAction (isAdmin() only for this type -- no guardian counterpart
// exists, unlike dobChange) before ever dispatching to this action, exactly like every other
// RespondTo*RequestAction in this codebase.
class RespondToAgeVerificationRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        // $responded (mirrors RespondToDobChangeRequestAction's own flag) reflects whether THIS
        // call actually transitioned the request, not merely its final status -- a second,
        // redundant call against an already-decided request must no-op the notification too, not
        // just the write (SCRUM-80/91's own precedent).
        [$request, $responded] = DB::transaction(function () use ($requestResponseDTO) {
            // TT-4.11c/SCRUM-304 security-review finding: the target User row is locked FIRST,
            // before the Request row -- matching SubmitAgeVerificationAction's own lock order
            // (User then Request), and RespondToDobChangeRequestAction's identical fix, for the
            // same two reasons: (1) avoids a lock-order inversion against a concurrent
            // resubmission of this same request, which locks User then updates the Request row;
            // (2) establishes one serialization point per user across both the dobChange and
            // ageVerification respond flows.
            $user = User::query()->lockForUpdate()->find($requestResponseDTO->request->for_id);

            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return [$request, false];
            }

            $request->update([
                'status' => is_null($requestResponseDTO->response)
                    ? RequestStatusEnum::rejected->value
                    : strtoupper($requestResponseDTO->response),
            ]);

            $request = $request->refresh();

            if ($request->status == RequestStatusEnum::accepted->value) {
                // TT-4.11c/SCRUM-304 security-review finding: verify the dob SNAPSHOTTED at
                // submission time (`data['attestedDob']`), not whatever `dob` happens to be
                // current on the User row right now -- the two can drift apart if review takes
                // a while (an unrelated edit, a concurrent dobChange approval), and applying the
                // live value would let an admin unknowingly certify a value nobody's attestation/
                // document was ever actually about. Falls back to the live value only for a
                // pre-existing request created before this snapshot field existed.
                $attestedDob = $request->data['attestedDob'] ?? $user->dob?->toDateString();

                ApplyVerifiedDobAction::new()->execute($user, $attestedDob, verified: true);

                // A verified dob is authoritative -- any still-pending dobChange request for the
                // same user is now moot and auto-closed as `superseded`, never `rejected`. Relies
                // on ShouldQueue+afterCommit() (like every notification in this action) to defer
                // actual dispatch past this transaction's commit even though it's called from
                // inside it -- mirrors RespondToRefundRequestAction's identical, established
                // in-transaction-notify pattern in this codebase (contrast with
                // RespondToGroupTherapyMembershipRequestAction's deliberately-outside-transaction
                // convention for its OWN notifications; both are correct for their own case).
                SupersedePendingDobChangeRequestsAction::new()->execute($user);
            }

            return [$request, true];
        });

        if ($responded && $request->status == RequestStatusEnum::accepted->value) {
            $request->for->notify(new AgeVerificationRequestApprovedNotification($request));
        }

        if ($responded && $request->status == RequestStatusEnum::rejected->value) {
            $request->for->notify(new AgeVerificationRequestRejectedNotification($request));
        }

        return $request;
    }
}
