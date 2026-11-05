<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\SafeguardingException\EnsureSafeguardingExceptionTermsAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
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

        // SCRUM-381: a safeguarding exception's reason may run to 2000 (its own limit).
        $max = $requestResponseDTO->request?->type === RequestTypeEnum::safeguardingException->value
            ? EnsureSafeguardingExceptionTermsAction::MAX_REASON
            : 1000;

        if (mb_strlen($requestResponseDTO->reason) > $max) {
            throw new BadRequestException("Reason must not be greater than {$max} characters.", 422);
        }
    }
}
