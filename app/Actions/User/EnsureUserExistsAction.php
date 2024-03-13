<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Exceptions\UserDoesNotExistException;
use App\Models\User;

class EnsureUserExistsAction extends Action
{
    public function execute(User $user, bool $throwException = false): bool {
        if (User::query()->where('id', $user->id)->exists()) return true;

        if (!$throwException) return false;

        throw new UserDoesNotExistException('User does not exist.', 422);
    }
}