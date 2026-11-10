<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\SessionScheduleProposalDTO;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Request;
use App\Notifications\SessionScheduleProposedNotification;
use Carbon\Carbon;

class ProposeSessionScheduleAction extends Action
{
    public function execute(SessionScheduleProposalDTO $dto): Request
    {
        // `from`/`to` always resolve to one User (the client) and one Counsellor (the assigned
        // counsellor), regardless of which of them is the acting party -- mirrors
        // ProposeOrganizationCounsellorCompensationChangeAction's from/to shape (there:
        // Organization/Counsellor; here: User/Counsellor), so a counter-offer (TT-2.5b) can flip
        // direction the same way.
        $clientIsActing = $dto->therapy->addedby->is($dto->user);

        $expiryDays = $dto->expiryDays ?? config('session_schedule_proposal.default_expiry_days');

        $request = CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'for' => $dto->therapy,
                'from' => $clientIsActing ? $dto->user : $dto->therapy->counsellor,
                'to' => $clientIsActing ? $dto->therapy->counsellor : $dto->therapy->addedby,
                'type' => RequestTypeEnum::sessionScheduleProposal->value,
                'data' => [
                    'startTime' => (new Carbon($dto->startTime))->utc()->toDateTimeString(),
                    'endTime' => (new Carbon($dto->endTime))->utc()->toDateTimeString(),
                    'name' => $dto->name,
                    'about' => $dto->about,
                    // sessions.type/payment_type are both NOT NULL -- default the same way
                    // CreateSessionFormModal.vue does for a direct session create (ONLINE unless
                    // in-person was actually chosen; FREE for a FREE therapy) so a proposal that
                    // never touches these fields still carries valid values through to accept-time
                    // CreateSessionAction. A PAID therapy has no such default -- CreateSessionScheduleProposalRequest
                    // requires paymentType explicitly in that case, so $dto->paymentType is never
                    // empty here for a paid therapy.
                    'type' => $dto->type ?: SessionTypeEnum::online->value,
                    'paymentType' => $dto->paymentType ?: TherapyPaymentTypeEnum::free->value,
                    'proposedById' => $dto->user->id,
                ],
                'expiresAt' => now()->addDays($expiryDays),
                'round' => 1,
            ])
        );

        $request->to->notify(new SessionScheduleProposedNotification($request));

        return $request;
    }
}
