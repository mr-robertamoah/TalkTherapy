<?php

namespace App\Services;

use App\Actions\Organization\CreateOrganizationAction;
use App\Actions\Organization\EnsureOrganizationDataIsValidAction;
use App\Actions\Organization\EnsureOrganizationExistsAction;
use App\Actions\Organization\EnsureUserIsOrganizationAdminAction;
use App\Actions\Organization\GetOrganizationCounsellorsAction;
use App\Actions\Organization\GetOrganizationMembersAction;
use App\Actions\Organization\UpdateOrganizationAction;
use App\DTOs\OrganizationDTO;
use App\Models\Organization;
use Illuminate\Pagination\LengthAwarePaginator;

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

    // Org-scoped lists, admin-only (TT-6.6a) -- the affiliated counsellors/members themselves
    // aren't in a position to browse everyone else affiliated with the org via this endpoint.
    public function getOrganizationMembers(OrganizationDTO $dto): LengthAwarePaginator
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        return GetOrganizationMembersAction::new()->execute($dto);
    }

    public function getOrganizationCounsellors(OrganizationDTO $dto): LengthAwarePaginator
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        return GetOrganizationCounsellorsAction::new()->execute($dto);
    }
}
