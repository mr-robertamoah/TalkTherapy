<?php

namespace App\Services;

use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\Organization\ApplyToOrganizationAsCounsellorAction;
use App\Actions\Organization\EnsureNoPendingOrganizationCounsellorRequestAction;
use App\Actions\Organization\EnsureOrganizationCanReceiveCounsellorApplicationsAction;
use App\Actions\Organization\EnsureOrganizationExistsAction;
use App\Actions\Organization\EnsureOrganizationIsProviderAction;
use App\Actions\Organization\EnsureOrganizationIsVerifiedAction;
use App\Actions\Organization\EnsureUserCanApplyToOrganizationAction;
use App\Actions\Organization\EnsureUserCanInviteOrganizationCounsellorAction;
use App\Actions\Organization\InviteCounsellorToOrganizationAction;
use App\DTOs\OrganizationCounsellorRequestDTO;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OrganizationCounsellorRequestService extends Service
{
    public function inviteCounsellor(OrganizationCounsellorRequestDTO $dto)
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanInviteOrganizationCounsellorAction::new()->execute($dto);

        EnsureOrganizationIsVerifiedAction::new()->execute($dto->organization);

        EnsureOrganizationIsProviderAction::new()->execute($dto->organization);

        // Locks the organization row for the duration of the transaction, serializing
        // concurrent invite/apply attempts for the same org so the pending-request check
        // below can't race a concurrent request past it.
        return DB::transaction(function () use ($dto) {
            Organization::query()->lockForUpdate()->find($dto->organization->id);

            EnsureNoPendingOrganizationCounsellorRequestAction::new()->execute($dto);

            return InviteCounsellorToOrganizationAction::new()->execute($dto);
        });
    }

    public function applyAsCounsellor(OrganizationCounsellorRequestDTO $dto)
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureCounsellorExistsAction::new()->execute($dto);

        EnsureUserCanApplyToOrganizationAction::new()->execute($dto);

        EnsureOrganizationCanReceiveCounsellorApplicationsAction::new()->execute($dto->organization);

        return DB::transaction(function () use ($dto) {
            Organization::query()->lockForUpdate()->find($dto->organization->id);

            EnsureNoPendingOrganizationCounsellorRequestAction::new()->execute($dto);

            return ApplyToOrganizationAsCounsellorAction::new()->execute($dto);
        });
    }
}
