<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Exceptions\OrganizationException;

class EnsureUserIsOrganizationOwnerAction extends Action
{
    // EnsureUserIsOrganizationAdminAction stays the gate for actions any admin may still do
    // (profile edits, counsellor/member invites) -- this is the owner-only gate for the actions
    // decided (2026-08-29) to require it: removing the organization, adding/removing/promoting/
    // demoting an admin (SCRUM-163).
    public function execute(OrganizationAdminDTO $dto): void
    {
        if (is_null($dto->user)) {
            throw new OrganizationException('You must be signed in to manage an organization.', 401);
        }

        // SCRUM-178: folds the null-organization check in here (same pattern as SCRUM-170's fix
        // to EnsureUserIsOrganizationAdminAction) -- a preceding standalone EnsureOrganizationExistsAction
        // call threw a distinct 404 before this action's own 403, letting any authenticated user
        // enumerate real organization ids on these admin-management routes by reading 404 vs 403.
        $role = is_null($dto->organization)
            ? null
            : $dto->organization->admins()->whereKey($dto->user->id)->first()?->pivot?->role;

        if ($role !== OrganizationAdminRoleEnum::owner->value) {
            throw new OrganizationException('You must be an owner of this organization to do this.', 403);
        }
    }
}
