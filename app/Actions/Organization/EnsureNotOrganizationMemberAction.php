<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Enums\OrganizationMemberStatusEnum;
use App\Exceptions\OrganizationException;
use App\Models\OrganizationMember;

// SCRUM-415: someone already a member has nothing to apply for -- accepting would only hand back the
// membership they have (CreateOrganizationMemberAction). Runs after the organisation's own (generic) check,
// so it only ever tells someone about their own membership.
class EnsureNotOrganizationMemberAction extends Action
{
    public const ALREADY_MEMBER = 'You are already a member of this organization.';

    public function execute(OrganizationMemberRequestDTO $dto): void
    {
        $isMember = OrganizationMember::query()
            ->where('organization_id', $dto->organization->id)
            ->where('user_id', $dto->member->id)
            ->where('status', OrganizationMemberStatusEnum::active->value)
            ->exists();

        if ($isMember) {
            throw new OrganizationException(self::ALREADY_MEMBER, 422);
        }
    }
}
