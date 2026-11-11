<?php

namespace App\Services;

use App\Actions\Organization\ApplyToOrganizationAsMemberAction;
use App\Actions\Organization\EnsureNoPendingOrganizationMemberRequestAction;
use App\Actions\Organization\EnsureNotOrganizationMemberAction;
use App\Actions\Organization\EnsureOrganizationCanReceiveMemberApplicationsAction;
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
        // SCRUM-178: no preceding EnsureOrganizationExistsAction here -- that would throw a
        // distinct 404 before EnsureUserCanInviteOrganizationMemberAction's own 403, reopening
        // the existence-enumeration oracle that action's own null-organization check now closes.
        // applyAsMember() below has the same fix, via SCRUM-179.
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
        // SCRUM-179: no preceding EnsureOrganizationExistsAction here -- that would throw a
        // distinct 404 before EnsureOrganizationCanReceiveMemberApplicationsAction's own
        // generic 422, reopening the existence-enumeration oracle that action's own
        // null-organization check now closes (mirrors SCRUM-170/178).
        // Refused early, before taking any lock; checked again below on the locked row.
        EnsureOrganizationCanReceiveMemberApplicationsAction::new()->execute($dto->organization);

        return DB::transaction(function () use ($dto) {
            // SCRUM-415: the checks read the locked row, not the copy loaded before the lock -- an admin
            // switching self-apply off at that moment is seen.
            $dto->organization = Organization::query()->lockForUpdate()->find($dto->organization->id);

            $this->ensureCanApplyAsMember($dto);

            return ApplyToOrganizationAsMemberAction::new()->execute($dto);
        });
    }

    // SCRUM-415: everything applying checks, in the order it checks it. A join link's page runs it
    // (unlocked) to say up front whether the person can apply; applyAsMember() runs it again under the
    // organisation's lock.
    public function ensureCanApplyAsMember(OrganizationMemberRequestDTO $dto): void
    {
        EnsureOrganizationCanReceiveMemberApplicationsAction::new()->execute($dto->organization);

        EnsureNotOrganizationMemberAction::new()->execute($dto);

        EnsureNoPendingOrganizationMemberRequestAction::new()->execute($dto);
    }
}
