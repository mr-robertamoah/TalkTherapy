<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Exceptions\OrganizationException;

class EnsureUserIsOrganizationAdminAction extends Action
{
    public function execute(OrganizationDTO $dto): void
    {
        if (is_null($dto->user)) {
            throw new OrganizationException('You must be signed in to manage an organization.', 401);
        }

        if (! $dto->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to manage this organization.', 403);
        }
    }
}
