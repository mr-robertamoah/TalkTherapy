<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;
use App\Notifications\OrganizationCounsellorCompensationChangeProposedNotification;
use Illuminate\Support\Facades\DB;

class CounterOfferOrganizationCounsellorCompensationChangeAction extends Action
{
    // Superseding the current request and creating its reverse-direction successor happen in
    // one lock-for-update transaction (mirrors RespondToOrganizationCounsellorCompensationRequestAction)
    // so a concurrent accept/reject/counter-offer on the same request can't race this one.
    public function execute(OrganizationCounsellorCompensationDTO $dto): Request
    {
        return DB::transaction(function () use ($dto) {
            $current = Request::query()->lockForUpdate()->findOrFail($dto->request->id);

            if ($current->status !== RequestStatusEnum::pending->value) {
                throw new OrganizationException('This proposal is no longer pending and can no longer be countered.', 422);
            }

            $maxRounds = config('organization.compensation_negotiation_max_rounds');

            if ($current->round >= $maxRounds) {
                throw new OrganizationException('This negotiation has reached its round limit; only accept or reject are available.', 422);
            }

            // A counter-offer supersedes the current terms -- not a third status, the same
            // flat-decline semantics as an outright reject (SCRUM-131 decision: "dispute" IS
            // counter-offering, there is no separate mediation/reason-field path).
            $current->update(['status' => RequestStatusEnum::rejected->value]);

            $organizationCounsellor = $current->for;
            $expiryDays = $dto->expiryDays ?? config('organization.compensation_negotiation_default_expiry_days');

            $counterOffer = CreateRequestAction::new()->execute(
                CreateRequestDTO::new()->fromArray([
                    'for' => $organizationCounsellor,
                    'from' => $current->to,
                    'to' => $current->from,
                    'type' => RequestTypeEnum::organizationCounsellorCompensationChange->value,
                    'data' => [
                        'type' => $dto->type,
                        'amount' => $dto->amount,
                        'currency' => $dto->currency,
                        'percentage' => $dto->percentage,
                        'basis' => $dto->basis,
                        'negotiatedRateAmount' => $dto->negotiatedRateAmount,
                        'proposedById' => $dto->user->id,
                    ],
                    'expiresAt' => now()->addDays($expiryDays),
                    'round' => $current->round + 1,
                ])
            );

            // SCRUM-151: the counterparty can be gone (a counsellor soft-deleted mid-negotiation, an
            // organization with no admins left). The counter-offer still stands; nobody is notified.
            NotifyCompensationRequestRecipientAction::new()->execute(
                $counterOffer,
                new OrganizationCounsellorCompensationChangeProposedNotification(
                    $organizationCounsellor->organization,
                    $counterOffer->data
                )
            );

            return $counterOffer;
        });
    }
}
