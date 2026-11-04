<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\DTOs\UpdateUserDTO;
use App\Notifications\AccountDeletedNotification;
use Illuminate\Support\Facades\DB;

class DeleteUserAction extends Action
{
    public function execute(UpdateUserDTO $updateUserDTO)
    {
        // SCRUM-288: as when someone deletes their own account (ProfileController::destroy) --
        // their wards are handled before the account goes, in the same transaction.
        DB::transaction(function () use ($updateUserDTO) {
            RemoveGuardianFromWardAction::new()->forAllWardsOf($updateUserDTO->updatedUser, RemoveGuardianFromWardAction::GUARDIAN_DELETED);

            $updateUserDTO->updatedUser->counsellor()->delete();
            $updateUserDTO->updatedUser->delete();
        });

        if ($updateUserDTO->updatedUser->email && $updateUserDTO->updatedUser->email_verified_at) {
            $updateUserDTO->updatedUser->notify(new AccountDeletedNotification);
        }

    }
}
