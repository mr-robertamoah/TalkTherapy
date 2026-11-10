<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanSetOrganizationCounsellorCompensationAction extends Action
{
    // Only gates who may *propose* new terms (org admins of this affiliation's organization).
    // Since SCRUM-146 (TT-6.4c), a proposal no longer takes effect unilaterally -- the
    // counsellor's accept/reject/counter-offer (SCRUM-147/148) is a separate authorization step.
    public function execute(OrganizationCounsellorCompensationDTO $dto): void
    {
        if (is_null($dto->user) || ! $dto->organizationCounsellor->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to set compensation terms for this affiliation.', 403);
        }
    }
}
