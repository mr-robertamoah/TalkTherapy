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

        $role = $dto->organization->admins()->whereKey($dto->user->id)->first()?->pivot?->role;

        if ($role !== OrganizationAdminRoleEnum::owner->value) {
            throw new OrganizationException('You must be an owner of this organization to do this.', 403);
        }
    }
}
