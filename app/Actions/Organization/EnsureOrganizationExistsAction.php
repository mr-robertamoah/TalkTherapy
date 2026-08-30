<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\DTOs\OrganizationCounsellorRequestDTO;
use App\DTOs\OrganizationDTO;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Exceptions\OrganizationException;

class EnsureOrganizationExistsAction extends Action
{
    public function execute(OrganizationDTO|OrganizationCounsellorRequestDTO|OrganizationMemberRequestDTO|OrganizationAdminDTO $dto): void
    {
        if (is_null($dto->organization)) {
            throw new OrganizationException('The organization you are trying to access was not found.', 404);
        }
    }
}
