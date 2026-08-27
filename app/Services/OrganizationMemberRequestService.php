<?php

namespace App\Services;

use App\Actions\Organization\ApplyToOrganizationAsMemberAction;
use App\Actions\Organization\EnsureNoPendingOrganizationMemberRequestAction;
use App\Actions\Organization\EnsureOrganizationCanReceiveMemberApplicationsAction;
use App\Actions\Organization\EnsureOrganizationExistsAction;
use App\Actions\Organization\EnsureOrganizationIsConsumerAction;
use App\Actions\Organization\EnsureOrganizationIsVerifiedAction;
use App\Actions\Organization\EnsureUserCanInviteOrganizationMemberAction;
use App\Actions\Organization\InviteMemberToOrganizationAction;
use App\Actions\User\EnsureUserExistsAction;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OrganizationMemberRequestService extends Service
{
    public function inviteMember(OrganizationMemberRequestDTO $dto)
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureUserExistsAction::new()->execute($dto->member, throwException: true);

        EnsureUserCanInviteOrganizationMemberAction::new()->execute($dto);

        EnsureOrganizationIsVerifiedAction::new()->execute($dto->organization);

        EnsureOrganizationIsConsumerAction::new()->execute($dto->organization);

        return DB::transaction(function () use ($dto) {
            Organization::query()->lockForUpdate()->find($dto->organization->id);

            EnsureNoPendingOrganizationMemberRequestAction::new()->execute($dto);

            return InviteMemberToOrganizationAction::new()->execute($dto);
        });
    }

    public function applyAsMember(OrganizationMemberRequestDTO $dto)
    {
        EnsureOrganizationExistsAction::new()->execute($dto);

        EnsureOrganizationCanReceiveMemberApplicationsAction::new()->execute($dto->organization);

        return DB::transaction(function () use ($dto) {
            Organization::query()->lockForUpdate()->find($dto->organization->id);

            EnsureNoPendingOrganizationMemberRequestAction::new()->execute($dto);

            return ApplyToOrganizationAsMemberAction::new()->execute($dto);
        });
    }
}
