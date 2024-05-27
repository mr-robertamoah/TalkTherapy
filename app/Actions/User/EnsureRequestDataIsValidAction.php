<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\UserException;

class EnsureRequestDataIsValidAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        if (
            $createRequestDTO->from &&
            $createRequestDTO->to &&
            $createRequestDTO->for
        ) return;

        throw new UserException("Not enough data was provided for a guardianship request.", 422);
    }
}