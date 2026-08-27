<?php

namespace App\Services;

use App\Actions\Organization\CreateOrganizationAction;
use App\Actions\Organization\EnsureOrganizationDataIsValidAction;
use App\Actions\Organization\EnsureOrganizationExistsAction;
use App\Actions\Organization\EnsureUserIsOrganizationAdminAction;
use App\Actions\Organization\UpdateOrganizationAction;
use App\DTOs\OrganizationDTO;
use App\Models\Organization;

class OrganizationService extends Service
{
    public function createOrganization(OrganizationDTO $dto): Organization
    {
        EnsureOrganizationDataIsValidAction::new()->execute($dto);

        return CreateOrganizationAction::new()->execute($dto);
    }

    public function updateOrganization(OrganizationDTO $dto): Organization
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        EnsureOrganizationDataIsValidAction::new()->execute($dto);

        return UpdateOrganizationAction::new()->execute($dto);
    }

    // Admin-only for now, matching this ticket's "admin CRUD" scope -- a broader
    // public/authenticated directory view (with a trimmed, public-safe field set) is a
    // separate, deliberate product decision, not an accidental side effect of only gating
    // writes.
    public function getOrganization(OrganizationDTO $dto): Organization
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        return $dto->organization;
    }
}
