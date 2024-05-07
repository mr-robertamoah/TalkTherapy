<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\UpdateUserDTO;
use Carbon\Carbon;

class UpdateUserAction extends Action
{
    public function execute(UpdateUserDTO $updateUserDTO)
    {
        $updateUserDTO->updatedUser->update([
            'firstName' => $updateUserDTO->firstName,
            'lastName' => $updateUserDTO->lastName,
            'otherNames' => $updateUserDTO->otherNames,
            'country' => $updateUserDTO->country,
            'dob' => (new Carbon($updateUserDTO->dob))->utc(),
            'email' => $updateUserDTO->email,
            'email_verified_at' => $updateUserDTO->emailVerified ? now()->utc() : null,
        ]);

        return $updateUserDTO->updatedUser->refresh();
    }
}