<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\BadRequestException;

class EnsureRequestResponseIsValidAction extends Action
{
    // Every RespondTo*RequestAction treats a null response as an implicit rejection, then
    // writes strtoupper($response) straight to the request's status -- a garbage value (e.g.
    // "MAYBE") would previously be written as-is, and since it's neither PENDING nor a real
    // terminal state, the request could never be responded to again through the normal
    // accept/reject path (SCRUM-89).
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        if (is_null($requestResponseDTO->response)) {
            return;
        }

        $response = strtoupper($requestResponseDTO->response);

        if (in_array($response, [RequestStatusEnum::accepted->value, RequestStatusEnum::rejected->value])) {
            return;
        }

        throw new BadRequestException("Response must be either 'accepted' or 'rejected'.", 422);
    }
}
