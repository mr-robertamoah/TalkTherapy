<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Exceptions\OrganizationException;

class EnsureOrganizationCounsellorExistsAction extends Action
{
    public function execute(OrganizationCounsellorCompensationDTO $dto): void
    {
        if (is_null($dto->organizationCounsellor)) {
            throw new OrganizationException('The affiliation you are trying to access was not found.', 404);
        }
    }
}
