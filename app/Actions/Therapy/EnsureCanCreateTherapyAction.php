<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Exceptions\CannotCreateTherapyException;
use App\Models\User;

class EnsureCanCreateTherapyAction extends Action
{
    public function execute(User $user)
    {
        if (
            $user->isAdmin() ||
            $user->isAdult() ||
            $user->hasGuardian()
            // TODO not banned from creating or suspending from the app
        ) return;

        throw new CannotCreateTherapyException("You are not allowed to create a therapy because you seem to be a minor without a guardian.", 422);
    }
}