<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\GetGuardianshipDTO;
use App\Exceptions\UserException;

class EnsureCanUpdateGuardianshipAction extends Action
{
    public function execute(GetGuardianshipDTO $getGuardianshipDTO)
    {
        if (
            $getGuardianshipDTO->user->isAdmin() ||
            $getGuardianshipDTO->guardianship->guardian_id == $getGuardianshipDTO->user->id
        ) return;

        throw new UserException("You cannot remove this guardianship because you are not the guardian.", 422);
    }
}