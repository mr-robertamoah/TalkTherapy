<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\GuardianAlertDTO;
use App\Enums\OrganizationMemberSourceEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\User;
use App\Notifications\OrganizationMemberJoinedGuardianNotification;
use App\Support\BestEffort;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreateOrganizationMemberAction extends Action
{
    // Unlike counsellor affiliation (TT-6.4a), membership has no compensation-terms gate --
    // it's `active` immediately, since billing-mode config (TT-6.3b) is a separate, later step
    // that doesn't block membership itself.
    // The person the membership is for: who applied, or who was invited.
    public static function memberOf(Request $request): ?User
    {
        return $request->from instanceof User ? $request->from : ($request->to instanceof User ? $request->to : null);
    }

    public function execute(Request $request): OrganizationMember
    {
        $organization = $request->for;
        $member = self::memberOf($request);

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
            $membership = OrganizationMember::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $member->id,
                'status' => OrganizationMemberStatusEnum::active->value,
                'source' => $source,
            ]);

            $this->tellGuardians($organization, $member, $source);

            return $membership;
        } catch (UniqueConstraintViolationException) {
            return OrganizationMember::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $member->id)
                ->first();
        }
    }

    // SCRUM-416: a minor's guardians are told they joined -- once the membership is committed, best effort
    // (as when a minor joins a group, AddGroupTherapyMemberAction). The live isAdult() check: a membership
    // keeps no minor-at-creation snapshot.
    private function tellGuardians(Organization $organization, User $member, string $source): void
    {
        $context = ['organization_id' => $organization->id, 'user_id' => $member->id];

        DB::afterCommit(fn () => BestEffort::attempt(
            fn () => AlertGuardianAction::new()->execute(GuardianAlertDTO::new()->fromArray([
                'user' => $member,
                'notification' => new OrganizationMemberJoinedGuardianNotification($organization, $source),
            ])),
            'organization-join-notify-guardians',
            $context,
        ));
    }
}
