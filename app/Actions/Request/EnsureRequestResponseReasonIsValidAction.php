<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Exceptions\BadRequestException;

class EnsureRequestResponseReasonIsValidAction extends Action
{
    // `reason` is a generic, optional RequestResponseDTO field (SCRUM-207: currently only read by
    // RejectSessionScheduleProposalAction) reached straight from client input with no FormRequest
    // in front of it -- security review flagged it as unbounded free text that ends up persisted
    // and emailed to another user, so it's validated here rather than left to each consumer.
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        if (is_null($requestResponseDTO->reason)) {
            return;
        }

        if (! is_string($requestResponseDTO->reason)) {
            throw new BadRequestException('Reason must be a string.', 422);
        }

        if (mb_strlen($requestResponseDTO->reason) > 1000) {
            throw new BadRequestException('Reason must not be greater than 1000 characters.', 422);
        }
    }
}
