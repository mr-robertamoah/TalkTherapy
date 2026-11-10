<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\DTOs\SessionScheduleProposalDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\SessionException;
use App\Models\Request;

class EnsureNoPendingSessionScheduleProposalAction extends Action
{
    // `for` is always this exact Therapy -- one pending negotiation is enough to block, regardless
    // of which direction (client or counsellor) it's currently pending in. Mirrors
    // EnsureNoPendingOrganizationCounsellorCompensationRequestAction's identical reasoning.
    public function execute(SessionScheduleProposalDTO $dto): void
    {
        $exists = Request::query()
            ->whereType(RequestTypeEnum::sessionScheduleProposal->value)
            ->wherePending()
            ->whereFor($dto->therapy)
            ->exists();

        if ($exists) {
            throw new SessionException('There is already a pending session schedule proposal for this therapy.', 422);
        }
    }
}
