<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanSetOrganizationCounsellorCompensationAction extends Action
{
    // Scope decision (SCRUM-122): only the org admin sets/renegotiates terms here -- there is
    // no counsellor-side accept/dispute step yet. A negotiation workflow is a natural follow-up,
    // not required by this ticket's acceptance criteria (capture + version the agreed terms).
    public function execute(OrganizationCounsellorCompensationDTO $dto): void
    {
        if (is_null($dto->user) || ! $dto->organizationCounsellor->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to set compensation terms for this affiliation.', 403);
        }
    }
}
