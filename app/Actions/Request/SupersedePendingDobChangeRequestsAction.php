<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Models\User;
use App\Notifications\DobChangeRequestSupersededNotification;

// TT-4.11c/SCRUM-304: called from inside RespondToAgeVerificationRequestAction's own locked
// transaction right after an approval -- if a dobChange request is ALSO still pending for the
// same user, admin-verified evidence has just made it moot, so it's closed as `superseded`
// (never `rejected`, which would misleadingly imply the guardian's submitted dob was judged
// wrong on its own merits). Deliberately one-directional: an ordinary dobChange approval must
// NEVER supersede a pending ageVerification request (a self-report is never "verified"), so this
// is only ever called from the ageVerification side, never the reverse.
class SupersedePendingDobChangeRequestsAction extends Action
{
    public function execute(User $user): void
    {
        Request::query()
            ->whereType(RequestTypeEnum::dobChange->value)
            ->wherePending()
            ->whereFor($user)
            ->get()
            ->each(function (Request $request) {
                $request->update(['status' => RequestStatusEnum::superseded->value]);

                $request->from?->notify(new DobChangeRequestSupersededNotification($request));
            });
    }
}
