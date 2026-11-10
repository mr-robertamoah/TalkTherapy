<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\Exceptions\OrganizationException;

class EnsureTargetIsNotAlreadyOrganizationAdminAction extends Action
{
    public function execute(OrganizationAdminDTO $dto): void
    {
        if ($dto->organization->isAdministeredBy($dto->admin)) {
            throw new OrganizationException('This user is already an admin of this organization.', 422);
        }
    }
}
