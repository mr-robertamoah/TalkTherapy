<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Exceptions\UserException;
use App\Models\User;

class EnsureUserMeetsTherapyRequirementsAction extends Action
{
    public function execute(User $user, bool $throwException = true): bool 
    {
        if (
            $user->isAdmin() ||
            (
                !!$user->dob &&
                !!$user->email_verified_at
                // TODO $user->isAdult && ($user->isNotAdult && $user->hasGuardian)
            )
        ) return true;

        if (!$throwException) return false;

        throw new UserException('User cannot partake in a therapy because user either does not have a verified email or has not set date of birth.', 422);
    }
}