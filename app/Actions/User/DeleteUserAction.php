<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\UpdateUserDTO;
use App\Notifications\AccountDeletedNotification;
use Carbon\Carbon;

class DeleteUserAction extends Action
{
    public function execute(UpdateUserDTO $updateUserDTO)
    {
        $updateUserDTO->updatedUser->counsellor()->delete();
        $updateUserDTO->updatedUser->delete();

        if ($updateUserDTO->updatedUser->email && $updateUserDTO->updatedUser->email_verified_at)
            $updateUserDTO->updatedUser->notify(new AccountDeletedNotification());

    }
}