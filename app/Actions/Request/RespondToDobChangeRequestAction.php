<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\Therapy;
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
                $this->applyApprovedDobChange($request);
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

    // TT-4.10c/SCRUM-292's own explicit decision: an approved change is a CORRECTION of the
    // truth, not a prospective-only change (unlike TT-3.1e-a's deliberately prospective-only
    // video-consent-mode switch -- that one protects a past grant from being retroactively
    // invalidated; this one corrects a person's actual historical age). Every currently-existing
    // qualifying record is updated to match the now-confirmed dob, not just the user's own
    // column -- otherwise TT-4.10b's own snapshot-preferring call sites would keep enforcing the
    // stale, pre-approval status forever.
    private function applyApprovedDobChange(Request $request): void
    {
        $user = $request->for;

        $user->update(['dob' => $request->data['newDob'] ?? null]);

        $isMinor = ! $user->refresh()->isAdult();

        Guardianship::query()->where('ward_id', $user->id)->update(['ward_was_minor_at_creation' => $isMinor]);

        Therapy::query()->where('addedby_type', User::class)->where('addedby_id', $user->id)
            ->update(['client_was_minor_at_creation' => $isMinor]);

        GroupTherapy::query()->where('addedby_type', User::class)->where('addedby_id', $user->id)
            ->update(['client_was_minor_at_creation' => $isMinor]);
    }
}
