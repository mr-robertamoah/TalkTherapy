<?php

namespace App\Services;

use App\Actions\Organization\CreateOrganizationMemberBillingConfigAction;
use App\Actions\Organization\EnsureOrganizationMemberBillingConfigDataIsValidAction;
use App\Actions\Organization\EnsureOrganizationMemberExistsAction;
use App\Actions\Organization\EnsureUserCanSetOrganizationMemberBillingConfigAction;
use App\DTOs\OrganizationMemberBillingConfigDTO;
use App\Models\OrganizationMemberBillingConfig;

class OrganizationMemberBillingConfigService extends Service
{
    public function setBillingConfig(OrganizationMemberBillingConfigDTO $dto): OrganizationMemberBillingConfig
    {
        EnsureOrganizationMemberExistsAction::new()->execute($dto);

        EnsureUserCanSetOrganizationMemberBillingConfigAction::new()->execute($dto);

        EnsureOrganizationMemberBillingConfigDataIsValidAction::new()->execute($dto);

        return CreateOrganizationMemberBillingConfigAction::new()->execute($dto);
    }
}
