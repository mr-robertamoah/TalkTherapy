<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\EnsureDobChangeIsAllowedDTO;
use App\DTOs\UpdateUserDTO;
use App\Exceptions\DobChangeRequiresApprovalException;
use Carbon\Carbon;

class UpdateUserAction extends Action
{
    public function execute(UpdateUserDTO $updateUserDTO)
    {
        $data = [
            'firstName' => $updateUserDTO->firstName,
            'lastName' => $updateUserDTO->lastName,
            'otherNames' => $updateUserDTO->otherNames,
            'country' => $updateUserDTO->country,
            'email' => $updateUserDTO->email,
            'email_verified_at' => $updateUserDTO->emailVerified ? now()->utc() : null,
        ];

        // TT-4.10c/SCRUM-292: `dob` is only ever included below when a value was actually
        // submitted -- previously this always ran `new Carbon($updateUserDTO->dob)`
        // unconditionally, and Carbon treats a null argument as "now", so an admin edit that
        // didn't touch dob at all would have silently reset the target's dob to today. Bundled
        // fix, discovered while adding the gate this exact line needed anyway.
        if ($updateUserDTO->dob) {
            try {
                EnsureDobChangeIsAllowedAction::new()->execute(
                    EnsureDobChangeIsAllowedDTO::new()->fromArray([
                        'user' => $updateUserDTO->updatedUser,
                        'actor' => $updateUserDTO->user,
                        'newDob' => $updateUserDTO->dob,
                    ])
                );

                $data['dob'] = (new Carbon($updateUserDTO->dob))->utc();

                // TT-4.11c/SCRUM-304 security-review finding: this is a direct, unverified admin
                // edit (bypassing ApplyVerifiedDobAction entirely) -- an existing "verified"
                // marker must not silently survive an edit it didn't apply to, mirroring
                // ProfileController::update()'s identical dob_verified_at clearing.
                $data['dob_verified_at'] = null;
            } catch (DobChangeRequiresApprovalException) {
                // dob omitted from $data -- a pending approval Request was already created by
                // the gate above. Everything else the admin submitted still applies below.
            }
        }

        $updateUserDTO->updatedUser->update($data);

        return $updateUserDTO->updatedUser->refresh();
    }
}
