<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanInviteOrganizationMemberAction extends Action
{
    public function execute(OrganizationMemberRequestDTO $dto): void
    {
        // SCRUM-178: is_null($dto->organization) folded in here (same pattern as SCRUM-170) --
        // a preceding standalone EnsureOrganizationExistsAction call threw a distinct 404 before
        // this action's own 403, letting any authenticated user enumerate real organization ids
        // on this invite route by reading 404 vs 403.
        if (is_null($dto->user) || is_null($dto->organization) || ! $dto->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to invite members to this organization.', 403);
        }
    }
}
