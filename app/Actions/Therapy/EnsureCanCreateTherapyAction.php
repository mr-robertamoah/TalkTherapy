<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Exceptions\CannotCreateTherapyWithoutGuardianException;
use App\Models\User;

// Also guards joining a group (JoinGroupTherapyAction). SCRUM-288: only an active guardian counts
// (see User::activeGuardianships()).
class EnsureCanCreateTherapyAction extends Action
{
    public function execute(User $user)
    {
        if (! $user->isPausedForMissingGuardian()) {
            return;
        }

        throw new CannotCreateTherapyWithoutGuardianException('You are not allowed to create a therapy because you seem to be a minor without a guardian.', 422);
    }
}
