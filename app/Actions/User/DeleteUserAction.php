<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\DTOs\UpdateUserDTO;
use App\Models\BlockedGoogleIdentity;
use App\Models\User;
use App\Notifications\AccountDeletedNotification;
use Illuminate\Support\Facades\DB;

class DeleteUserAction extends Action
{
    public function execute(UpdateUserDTO $updateUserDTO)
    {
        // SCRUM-288: as when someone deletes their own account (ProfileController::destroy) --
        // their wards are handled before the account goes, in the same transaction.
        DB::transaction(function () use ($updateUserDTO) {
            // SCRUM-456: an administrator closing someone's account also keeps its Google account from signing up or
            // connecting again (closing clears google_id, SCRUM-452). Read from the row, whatever this instance was
            // loaded with, and locked (security review), so a Google account connected at the same moment isn't missed.
            // A super administrator closing their own account here closes it as anyone would: no block.
            $googleId = User::query()->whereKey($updateUserDTO->updatedUser->id)->lockForUpdate()->value('google_id');

            if (filled($googleId) && ! $updateUserDTO->updatedUser->is($updateUserDTO->user)) {
                BlockedGoogleIdentity::block($googleId, BlockedGoogleIdentity::REASON_CLOSED_BY_ADMIN);
            }

            RemoveGuardianFromWardAction::new()->forAllWardsOf($updateUserDTO->updatedUser, RemoveGuardianFromWardAction::GUARDIAN_DELETED);

            $updateUserDTO->updatedUser->counsellor()->delete();
            $updateUserDTO->updatedUser->delete();
        });

        if ($updateUserDTO->updatedUser->email && $updateUserDTO->updatedUser->email_verified_at) {
            $updateUserDTO->updatedUser->notify(new AccountDeletedNotification);
        }

    }
}
