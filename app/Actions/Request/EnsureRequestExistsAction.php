<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Exceptions\RequestNotFoundException;

class EnsureRequestExistsAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        if ($requestResponseDTO->request) return;

        throw new RequestNotFoundException("Request was not found.", 422);
    }
}