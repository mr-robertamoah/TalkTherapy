<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanInviteOrganizationMemberAction extends Action
{
    public function execute(OrganizationMemberRequestDTO $dto): void
    {
        if (is_null($dto->user) || ! $dto->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to invite members to this organization.', 403);
        }
    }
}
