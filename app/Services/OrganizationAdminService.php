<?php

namespace App\Services;

use App\Actions\Organization\AddOrganizationAdminAction;
use App\Actions\Organization\EnsureOrganizationAdminTargetExistsAction;
use App\Actions\Organization\EnsureOrganizationExistsAction;
use App\Actions\Organization\EnsureOrganizationRetainsAnOwnerAction;
use App\Actions\Organization\EnsureTargetIsNotAlreadyOrganizationAdminAction;
use App\Actions\Organization\EnsureTargetIsOrganizationAdminAction;
use App\Actions\Organization\EnsureUserIsOrganizationOwnerAction;
use App\Actions\Organization\RemoveOrganizationAdminAction;
use App\Actions\Organization\UpdateOrganizationAdminRoleAction;
use App\DTOs\OrganizationAdminDTO;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

// Direct actions, not the Request/respond negotiation flow -- there's no second-party consent
// being negotiated here (architect-recommended pattern, SCRUM-163).
class OrganizationAdminService extends Service
{
    public function addAdmin(OrganizationAdminDTO $dto): Organization
    {
        EnsureOrganizationExistsAction::new()->execute($dto);
        EnsureUserIsOrganizationOwnerAction::new()->execute($dto);
        EnsureOrganizationAdminTargetExistsAction::new()->execute($dto);
        EnsureTargetIsNotAlreadyOrganizationAdminAction::new()->execute($dto);

        return AddOrganizationAdminAction::new()->execute($dto);
    }

    public function removeAdmin(OrganizationAdminDTO $dto): Organization
    {
        EnsureOrganizationExistsAction::new()->execute($dto);
        EnsureUserIsOrganizationOwnerAction::new()->execute($dto);
        EnsureOrganizationAdminTargetExistsAction::new()->execute($dto);
        EnsureTargetIsOrganizationAdminAction::new()->execute($dto);

        // Locks the organization row for the duration of the transaction, serializing concurrent
        // remove/demote attempts against the same org so the owner-count check below can't race
        // a concurrent request past it and leave zero owners (security review, SCRUM-163),
        // mirroring OrganizationCounsellorRequestService's identical pattern.
        return DB::transaction(function () use ($dto) {
            Organization::query()->lockForUpdate()->find($dto->organization->id);

            EnsureOrganizationRetainsAnOwnerAction::new()->execute($dto);

            return RemoveOrganizationAdminAction::new()->execute($dto);
        });
    }

    public function updateAdminRole(OrganizationAdminDTO $dto): Organization
    {
        EnsureOrganizationExistsAction::new()->execute($dto);
        EnsureUserIsOrganizationOwnerAction::new()->execute($dto);
        EnsureOrganizationAdminTargetExistsAction::new()->execute($dto);
        EnsureTargetIsOrganizationAdminAction::new()->execute($dto);

        return DB::transaction(function () use ($dto) {
            Organization::query()->lockForUpdate()->find($dto->organization->id);

            EnsureOrganizationRetainsAnOwnerAction::new()->execute($dto);

            return UpdateOrganizationAdminRoleAction::new()->execute($dto);
        });
    }
}
