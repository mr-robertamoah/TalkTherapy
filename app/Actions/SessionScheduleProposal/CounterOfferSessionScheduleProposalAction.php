<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\SessionScheduleProposalDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\SessionException;
use App\Models\Request;
use App\Notifications\SessionScheduleProposedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CounterOfferSessionScheduleProposalAction extends Action
{
    // Superseding the current request and creating its reverse-direction successor happen in one
    // lock-for-update transaction, mirroring CounterOfferOrganizationCounsellorCompensationChangeAction.
    public function execute(SessionScheduleProposalDTO $dto): Request
    {
        $counterOffer = DB::transaction(function () use ($dto) {
            $current = Request::query()->lockForUpdate()->findOrFail($dto->request->id);

            if ($current->status !== RequestStatusEnum::pending->value) {
                throw new SessionException('This proposal is no longer pending and can no longer be countered.', 422);
            }

            $maxRounds = config('session_schedule_proposal.max_rounds');

            if ($current->round >= $maxRounds) {
                throw new SessionException('This negotiation has reached its round limit; only accept or reject are available.', 422);
            }

            // A counter-offer supersedes the current proposal -- not a third status, the same
            // flat-decline semantics as an outright reject (mirrors the identical SCRUM-131
            // decision for compensation counter-offers: countering IS declining-with-a-new-offer,
            // there is no separate "superseded" status).
            $current->update(['status' => RequestStatusEnum::rejected->value]);

            $therapy = $current->for;

            $expiryDays = $dto->expiryDays ?? config('session_schedule_proposal.default_expiry_days');

            return CreateRequestAction::new()->execute(
                CreateRequestDTO::new()->fromArray([
                    'for' => $therapy,
                    'from' => $current->to,
                    'to' => $current->from,
                    'type' => RequestTypeEnum::sessionScheduleProposal->value,
                    'data' => [
                        'startTime' => (new Carbon($dto->startTime))->utc()->toDateTimeString(),
                        'endTime' => (new Carbon($dto->endTime))->utc()->toDateTimeString(),
                        'name' => $dto->name ?? $current->data['name'] ?? null,
                        'about' => $dto->about ?? $current->data['about'] ?? null,
                        // ?: not ?? -- an empty string must fall back the same as a missing/null
                        // value, not persist as-is (the propose-time bug this mirrors: sessions.type/
                        // payment_type are NOT NULL, and ProposeSessionScheduleAction guarantees
                        // $current->data already holds a valid, non-empty value for both).
                        'type' => $dto->type ?: $current->data['type'] ?? null,
                        'paymentType' => $dto->paymentType ?: $current->data['paymentType'] ?? null,
                        'proposedById' => $dto->user->id,
                    ],
                    'expiresAt' => now()->addDays($expiryDays),
                    'round' => $current->round + 1,
                ])
            );
        });

        $counterOffer->to->notify(new SessionScheduleProposedNotification($counterOffer));

        return $counterOffer;
    }
}
