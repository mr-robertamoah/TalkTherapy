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
        ) {
            return;
        }

        // Shared by guardianship and discussion requests (SCRUM-344) -- so no request type in the text.
        throw new UserException('Not enough data was provided for this request.', 422);
    }
}
