<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberBillingConfigDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanSetOrganizationMemberBillingConfigAction extends Action
{
    // Scope decision, mirrors compensation terms (TT-6.4b): only the org admin sets billing
    // config -- no member-side accept/negotiate step for this ticket's acceptance criteria.
    public function execute(OrganizationMemberBillingConfigDTO $dto): void
    {
        if (is_null($dto->user) || ! $dto->organizationMember->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to set billing configuration for this membership.', 403);
        }
    }
}
