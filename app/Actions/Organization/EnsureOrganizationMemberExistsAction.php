<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberBillingConfigDTO;
use App\Exceptions\OrganizationException;

class EnsureOrganizationMemberExistsAction extends Action
{
    public function execute(OrganizationMemberBillingConfigDTO $dto): void
    {
        if (is_null($dto->organizationMember)) {
            throw new OrganizationException('The membership you are trying to access was not found.', 404);
        }
    }
}
