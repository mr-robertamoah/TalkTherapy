<?php

namespace App\Services;

use App\Actions\Organization\CreateOrganizationCounsellorCompensationAction;
use App\Actions\Organization\EnsureOrganizationCounsellorCompensationDataIsValidAction;
use App\Actions\Organization\EnsureOrganizationCounsellorExistsAction;
use App\Actions\Organization\EnsureUserCanSetOrganizationCounsellorCompensationAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Models\OrganizationCounsellorCompensation;

class OrganizationCounsellorCompensationService extends Service
{
    public function setCompensation(OrganizationCounsellorCompensationDTO $dto): OrganizationCounsellorCompensation
    {
        EnsureOrganizationCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanSetOrganizationCounsellorCompensationAction::new()->execute($dto);

        EnsureOrganizationCounsellorCompensationDataIsValidAction::new()->execute($dto);

        return CreateOrganizationCounsellorCompensationAction::new()->execute($dto);
    }
}
