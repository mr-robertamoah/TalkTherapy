<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\Exceptions\OrganizationException;

class EnsureOrganizationAdminTargetExistsAction extends Action
{
    public function execute(OrganizationAdminDTO $dto): void
    {
        if (is_null($dto->admin)) {
            throw new OrganizationException('The user you are trying to manage was not found.', 404);
        }
    }
}
