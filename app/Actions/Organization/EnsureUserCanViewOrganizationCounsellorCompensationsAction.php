<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Exceptions\OrganizationException;

class EnsureUserCanViewOrganizationCounsellorCompensationsAction extends Action
{
    // Read-side scoping (SCRUM-123): the affiliation's organization admins (mirrors the write
    // side, EnsureUserCanSetOrganizationCounsellorCompensationAction) plus the affiliated
    // counsellor themselves -- there was previously no read path at all, so a counsellor
    // couldn't see the terms an admin had unilaterally set for them.
    public function execute(OrganizationCounsellorCompensationDTO $dto): void
    {
        if (is_null($dto->user)) {
            throw new OrganizationException('You are not authorized to view compensation terms for this affiliation.', 403);
        }

        if ($dto->organizationCounsellor->organization->isAdministeredBy($dto->user)) {
            return;
        }

        if ($dto->organizationCounsellor->counsellor->user_id === $dto->user->id) {
            return;
        }

        throw new OrganizationException('You are not authorized to view compensation terms for this affiliation.', 403);
    }
}
