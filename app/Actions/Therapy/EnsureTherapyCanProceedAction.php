<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Actions\Guardianship\EnsureMinorHasActiveGuardianAction;
use App\Actions\Suspension\EnsureTherapyCounsellorIsAvailableAction;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-288: the one gate for running a therapy -- its counsellor isn't suspended (SCRUM-359,
// $includeTarget as there) and it isn't paused for a minor's missing guardian ($counterparty,
// $fresh and $actsAsCounsellor as EnsureMinorHasActiveGuardianAction's).
//
// Used for sessions, topics, schedule proposals (and accepting one), session messages and joining
// a call. A few writes only take the guardian half, because the suspension half either doesn't
// apply or is already checked there in its own way:
//  - therapy/group settings, assistance requests (TherapyService, GroupTherapyService) -- no
//    counsellor work; SCRUM-359 checks the counsellor being asked separately;
//  - accepting a request (RequestService, the membership/assistance actions, under their locks) --
//    RequestService::ensureNoSuspendedCounsellorIsTakenOn() checks both sides for suspension;
//  - creating a therapy-counsellor link and using one, paying, and the video join's re-check under
//    its lock (JoinVideoSessionAction).
class EnsureTherapyCanProceedAction extends Action
{
    public function execute(
        ?User $user,
        Therapy|GroupTherapy|null $therapy,
        bool $includeTarget = true,
        bool $fresh = false,
        bool $counterparty = true,
        ?bool $actsAsCounsellor = null,
    ): void {
        EnsureTherapyCounsellorIsAvailableAction::new()->execute($user, $therapy, $includeTarget);

        EnsureMinorHasActiveGuardianAction::new()->execute($user, $therapy, $counterparty, $fresh, $actsAsCounsellor);
    }
}
