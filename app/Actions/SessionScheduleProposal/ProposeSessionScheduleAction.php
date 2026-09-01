<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\SessionScheduleProposalDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use Carbon\Carbon;

class ProposeSessionScheduleAction extends Action
{
    // No notification is sent here -- deliberately deferred to TT-2.5b (SCRUM-207), which owns
    // "notifications on every new proposal/counter to the current recipient" per that ticket's
    // scope, so propose/counter/accept/reject all notify the same way rather than this action
    // building its own one-off.
    public function execute(SessionScheduleProposalDTO $dto): Request
    {
        // `from`/`to` always resolve to one User (the client) and one Counsellor (the assigned
        // counsellor), regardless of which of them is the acting party -- mirrors
        // ProposeOrganizationCounsellorCompensationChangeAction's from/to shape (there:
        // Organization/Counsellor; here: User/Counsellor), so a counter-offer (TT-2.5b) can flip
        // direction the same way.
        $clientIsActing = $dto->therapy->addedby->is($dto->user);

        $expiryDays = $dto->expiryDays ?? config('session_schedule_proposal.default_expiry_days');

        return CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'for' => $dto->therapy,
                'from' => $clientIsActing ? $dto->user : $dto->therapy->counsellor,
                'to' => $clientIsActing ? $dto->therapy->counsellor : $dto->therapy->addedby,
                'type' => RequestTypeEnum::sessionScheduleProposal->value,
                'data' => [
                    'startTime' => (new Carbon($dto->startTime))->utc()->toDateTimeString(),
                    'endTime' => (new Carbon($dto->endTime))->utc()->toDateTimeString(),
                    'name' => $dto->name,
                    'type' => $dto->type,
                    'paymentType' => $dto->paymentType,
                    'proposedById' => $dto->user->id,
                ],
                'expiresAt' => now()->addDays($expiryDays),
                'round' => 1,
            ])
        );
    }
}
