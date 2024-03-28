<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Exceptions\CannotRespondToRequestException;

class EnsureUserCanRespondToRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $respondent = $requestResponseDTO->request->to;
        if (
            $requestResponseDTO->user->isAdmin() ||
            $respondent->is($requestResponseDTO->user) ||
            $respondent->is($requestResponseDTO->user?->counsellor)
        ) return;

        throw new CannotRespondToRequestException("You are not allowed to respond to this request.", 422);
    }
}