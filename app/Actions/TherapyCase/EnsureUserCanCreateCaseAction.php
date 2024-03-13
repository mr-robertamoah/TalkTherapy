<?php

namespace App\Actions\TherapyCase;
use App\Actions\Action;
use App\Actions\User\EnsureUserExistsAction;
use App\Exceptions\UserCanCreateCaseException;
use App\Models\User;

class EnsureUserCanCreateCaseAction extends Action
{
    public function execute(User $user) {
        if (EnsureUserExistsAction::new()->execute($user)) return;

        throw new UserCanCreateCaseException('You are not allowed to create a therapy case.', 422);
    }
}