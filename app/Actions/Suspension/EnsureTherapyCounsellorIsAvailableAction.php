<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-359: for anything that runs a therapy's sessions (scheduling, starting, joining a call,
// proposing a time): refused when $user is a suspended counsellor of it, and -- for an individual
// therapy -- when its one counsellor is suspended, whoever asks ($includeTarget: false checks only
// $user's own role, e.g. for messages). A group carries on with its other counsellors.
class EnsureTherapyCounsellorIsAvailableAction extends Action
{
    public function execute(?User $user, Therapy|GroupTherapy|null $therapy, bool $includeTarget = true): void
    {
        if (! $user || ! $therapy) {
            return;
        }

        $own = $user->counsellor;

        if ($own && $therapy->isCounsellor($own)) {
            EnsureCanActAsCounsellorAction::new()->execute($own);
        }

        if ($includeTarget && $therapy instanceof Therapy) {
            EnsureCanActAsCounsellorAction::new()->execute($therapy->counsellor, asTarget: true);
        }
    }
}
