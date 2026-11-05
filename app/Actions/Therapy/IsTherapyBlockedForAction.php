<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\AppException;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-382: whether $user still can't carry on in $therapy, for anything that is about to tell them
// (or its counsellor) that it can -- a resume after a guardian comes back, a super administrator's
// safeguarding exception (SCRUM-381; both through HandleGuardianRestoredAction::carryingOn()), and
// a renewal or reminder of one. One place, so no pause-lifter can announce "resumed" while
// something else still blocks it:
//  - the therapy has ended;
//  - $user's account is suspended (the middleware stops them using the app at all);
//  - the write gate itself refuses them: EnsureTherapyCanProceedAction (its counsellor is
//    suspended, or it is still paused for a missing guardian), re-read ($fresh).
class IsTherapyBlockedForAction extends Action
{
    public function execute(User $user, Therapy|GroupTherapy $therapy): bool
    {
        if ($therapy->status === TherapyStatusEnum::ended->value) {
            return true;
        }

        if ($user->forgetActiveSuspension()->activeSuspension(SuspensionScopeEnum::account)) {
            return true;
        }

        try {
            EnsureTherapyCanProceedAction::new()->execute($user, $therapy, fresh: true);
        } catch (AppException) {
            return true;
        }

        return false;
    }
}
