<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationAdminDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Exceptions\OrganizationException;

class EnsureOrganizationRetainsAnOwnerAction extends Action
{
    // Shared by both removeAdmin (dto->role is always null) and updateAdminRole (dto->role is
    // the new role) -- an org must never end up with zero owners (SCRUM-163 AC4).
    public function execute(OrganizationAdminDTO $dto): void
    {
        $targetRole = $dto->organization->admins()->whereKey($dto->admin->id)->first()?->pivot?->role;

        // Acting on a non-owner (removing a plain admin, or promoting one) can never reduce the
        // owner count.
        if ($targetRole !== OrganizationAdminRoleEnum::owner->value) {
            return;
        }

        // Only a role change AWAY from owner reduces the count -- removeAdmin's dto->role is
        // always null here, which correctly falls through to the count check below.
        if ($dto->role === OrganizationAdminRoleEnum::owner->value) {
            return;
        }

        $ownerCount = $dto->organization->admins()
            ->wherePivot('role', OrganizationAdminRoleEnum::owner->value)
            ->count();

        if ($ownerCount <= 1) {
            throw new OrganizationException('An organization must always have at least one owner.', 422);
        }
    }
}
