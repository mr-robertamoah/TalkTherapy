<?php

namespace App\Actions\Language;

use App\Actions\Action;
use App\Actions\User\EnsureUserExistsAction;
use App\Exceptions\UserCanCreateLanguageException;
use App\Models\User;

class EnsureUserCanCreateLanguageAction extends Action
{
    public function execute(User $user) {
        if (EnsureUserExistsAction::new()->execute($user)) return;

        throw new UserCanCreateLanguageException('You are not allowed to create a therapy language.');
    }
}