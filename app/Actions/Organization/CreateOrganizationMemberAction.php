<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationMemberSourceEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateOrganizationMemberAction extends Action
{
    // Unlike counsellor affiliation (TT-6.4a), membership has no compensation-terms gate --
    // it's `active` immediately, since billing-mode config (TT-6.3b) is a separate, later step
    // that doesn't block membership itself.
    public function execute(Request $request): OrganizationMember
    {
        $organization = $request->for;
        $member = $request->from instanceof User ? $request->from : $request->to;

        $existing = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $source = $request->type === RequestTypeEnum::organizationMemberApplication->value
            ? OrganizationMemberSourceEnum::applied->value
            : OrganizationMemberSourceEnum::invited->value;

        // Mirrors CreateOrganizationCounsellorAffiliationAction's race handling.
        try {
            return OrganizationMember::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $member->id,
                'status' => OrganizationMemberStatusEnum::active->value,
                'source' => $source,
            ]);
        } catch (UniqueConstraintViolationException) {
            return OrganizationMember::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $member->id)
                ->first();
        }
    }
}
