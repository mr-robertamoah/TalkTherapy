<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\GetGuardianshipDTO;
use App\Exceptions\UserException;

class EnsureGuardianshipExistsAction extends Action
{
    public function execute(GetGuardianshipDTO $getGuardianshipDTO)
    {
        if ($getGuardianshipDTO->guardianship) return;

        throw new UserException("The guardianship was not found.", 422);
    }
}