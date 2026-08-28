<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;

class EnsureNoPendingOrganizationCounsellorCompensationRequestAction extends Action
{
    // Unlike EnsureNoPendingOrganizationCounsellorRequestAction (which needs an OR across
    // direction since `for` there is the Organization, shared by many affiliations), `for` here
    // is always this exact OrganizationCounsellor row -- so one pending negotiation for this
    // affiliation, regardless of which direction it's currently pending in, is enough to block.
    public function execute(OrganizationCounsellorCompensationDTO $dto): void
    {
        $exists = Request::query()
            ->whereType(RequestTypeEnum::organizationCounsellorCompensationChange->value)
            ->wherePending()
            ->whereFor($dto->organizationCounsellor)
            ->exists();

        if ($exists) {
            throw new OrganizationException('There is already a pending compensation-change negotiation for this affiliation.', 422);
        }
    }
}
