<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberBillingConfigDTO;
use App\Models\OrganizationMemberBillingConfig;

class CreateOrganizationMemberBillingConfigAction extends Action
{
    // Always inserts -- never updates an existing row, mirroring
    // CreateOrganizationCounsellorCompensationAction (TT-6.4b)'s effective-dated history shape.
    public function execute(OrganizationMemberBillingConfigDTO $dto): OrganizationMemberBillingConfig
    {
        return OrganizationMemberBillingConfig::create([
            'organization_member_id' => $dto->organizationMember->id,
            'mode' => $dto->mode,
            'per' => $dto->per,
            'include_group_therapies' => $dto->includeGroupTherapies,
            'effective_from' => now(),
        ]);
    }
}
