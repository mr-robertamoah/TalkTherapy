<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Notifications\OrganizationCounsellorCompensationChangeProposedNotification;

class ProposeOrganizationCounsellorCompensationChangeAction extends Action
{
    // SCRUM-146 (TT-6.4c): creates a Request instead of writing directly to
    // organization_counsellor_compensations -- that table only ever receives already-accepted
    // terms (SCRUM-147's job). `for` is the affiliation itself, not the Organization, so it
    // uniquely identifies this negotiation regardless of which direction it's currently pending in.
    public function execute(OrganizationCounsellorCompensationDTO $dto): Request
    {
        $organizationCounsellor = $dto->organizationCounsellor;
        $expiryDays = $dto->expiryDays ?? config('organization.compensation_negotiation_default_expiry_days');

        $request = CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'for' => $organizationCounsellor,
                'from' => $organizationCounsellor->organization,
                'to' => $organizationCounsellor->counsellor,
                'type' => RequestTypeEnum::organizationCounsellorCompensationChange->value,
                'data' => [
                    'type' => $dto->type,
                    'amount' => $dto->amount,
                    'currency' => $dto->currency,
                    'percentage' => $dto->percentage,
                    'basis' => $dto->basis,
                    // SCRUM-147: the accept step attributes the resulting compensation row's
                    // set_by_id to whoever actually proposed these terms, not whoever clicks
                    // accept -- record it here since `from` is the Organization, not a User.
                    'proposedById' => $dto->user->id,
                ],
                'expiresAt' => now()->addDays($expiryDays),
                'round' => 1,
            ])
        );

        $organizationCounsellor->counsellor->notify(
            new OrganizationCounsellorCompensationChangeProposedNotification($organizationCounsellor->organization, $request->data)
        );

        return $request;
    }
}
