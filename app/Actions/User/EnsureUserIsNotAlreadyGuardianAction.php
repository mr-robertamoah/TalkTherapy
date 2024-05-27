<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\UserException;

class EnsureUserIsNotAlreadyGuardianAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        if (
            !$createRequestDTO->from->guardians()->where('guardian_id', $createRequestDTO->to->id)->exists()
        ) return;

        throw new UserException("The person you are trying to send a guardianship request to is already your guardian.", 422);
    }
}