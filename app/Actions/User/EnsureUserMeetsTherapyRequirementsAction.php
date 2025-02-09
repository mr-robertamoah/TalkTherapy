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
            )
        ) return true;

        if (!$throwException) return false;

        throw new UserException(
            'User cannot partake in a therapy because of any or a combination of the following: you do not have a verified email, you have not set date of birth.',
            422
        );
    }
}