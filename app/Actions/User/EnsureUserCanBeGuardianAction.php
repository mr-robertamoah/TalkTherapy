<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\UserException;

class EnsureUserCanBeGuardianAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO, ?string $errMessage = null)
    {
        $age = $createRequestDTO->to->age;

        if (
            $age &&
            $age >= 18 &&
            $createRequestDTO->to->email &&
            $createRequestDTO->to->email_verified_at
        ) return;

        throw new UserException($errMessage ?: "The user you are trying to send a guardianship request does not qualify to be a guardian because he/she is not an adult, has not set date or birth, has not set email or has not verified his/her email.", 422);
    }
}