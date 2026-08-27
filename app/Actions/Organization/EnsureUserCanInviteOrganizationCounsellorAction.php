<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorRequestDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanInviteOrganizationCounsellorAction extends Action
{
    public function execute(OrganizationCounsellorRequestDTO $dto): void
    {
        if (is_null($dto->user) || ! $dto->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to invite counsellors to this organization.', 403);
        }
    }
}
