<?php

namespace App\Actions\Religion;

use App\Actions\Action;
use App\Actions\User\EnsureUserExistsAction;
use App\Exceptions\UserCanCreateReligionException;
use App\Models\User;

class EnsureUserCanCreateReligionAction extends Action
{
    public function execute(User $user) {
        if (EnsureUserExistsAction::new()->execute($user)) return;

        throw new UserCanCreateReligionException('You are not allowed to create a religion.', 422);
    }
}