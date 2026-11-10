<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\ApplyVerifiedDobAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Request;
use App\Models\User;
use App\Notifications\DobChangeRequestApprovedNotification;
use App\Notifications\DobChangeRequestRejectedNotification;
use Illuminate\Support\Facades\DB;

// TT-4.10d/SCRUM-293: applies the actual side effect on accept, mirroring
// RespondToGuardianshipRequestAction's own "lock the request, re-check pending status inside the
// lock, apply on accept" shape -- no negotiation rounds needed here, just approve/reject.
//
// Authorization is NOT re-checked here -- RequestService::respondToRequest() already runs
// EnsureUserCanRespondToRequestAction (admin, OR the specific addressed `to` guardian, OR any
// admin when `to` is null) before ever dispatching to this action, exactly like every other
// RespondTo*RequestAction in this codebase.
class RespondToDobChangeRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        // $responded (mirrors RespondToGuardianshipRequestAction's own $created flag) reflects
        // whether THIS call actually transitioned the request, not merely its final status --
        // a second, redundant call against an already-decided request (a race, a stale UI, a
        // double-click) must no-op the notification too, not just the write (SCRUM-80/91's own
        // precedent). Checking $request->status alone after the transaction can't tell "I just
        // decided this" apart from "someone else already had," and would re-send on every
        // no-op call.
        [$request, $responded] = DB::transaction(function () use ($requestResponseDTO) {
            // TT-4.11c/SCRUM-304 security-review finding: the target User row is locked FIRST,
            // before the Request row -- matching SubmitAgeVerificationAction's and
            // EnsureDobChangeIsAllowedAction's own lock order (User then Request) on the
            // submission side. Locking Request first here (as this action used to) while those
            // submission-side actions lock User first is a lock-order inversion: a user
            // resubmitting/editing their still-pending request concurrently with an admin/
            // guardian responding to it could deadlock (each transaction holding one row's lock
            // and waiting on the other's). One consistent global order closes it. This also
            // establishes the same serialization point a concurrent dobChange-approval and
            // age-verification-approval for the same user need (see
            // RespondToAgeVerificationRequestAction's identical ordering).
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
                ApplyVerifiedDobAction::new()->execute($user, $request->data['newDob'] ?? null);
            }

            return [$request, true];
        });

        if ($responded && $request->status == RequestStatusEnum::accepted->value) {
            $request->from->notify(new DobChangeRequestApprovedNotification($request));
        }

        if ($responded && $request->status == RequestStatusEnum::rejected->value) {
            $request->from->notify(new DobChangeRequestRejectedNotification($request));
        }

        return $request;
    }
}
