<?php

use App\Actions\Organization\GetRetainerCoveringOrganizationAction;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Therapy;
use App\Models\User;

// TT-7.3b-k/SCRUM-242: extracted from EnsureStrictPaymentGateSatisfiedAction's own retainer-
// coverage query (SCRUM-237) so the client-facing disclosure UI can ask the same question without
// a second, drifting copy. EnsureStrictPaymentGateSatisfiedActionTest already covers the
// bypass-eligibility matrix through that call site -- this file pins down the action's own
// contract (an Organization, not a bool) directly.

function coveredTherapy(Counsellor $counsellor): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
}

function activeRetainerMembership(Counsellor $counsellor, User $user): OrganizationMember
{
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);

    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    return $member;
}

test('returns the covering organization for a retainer-covered engagement', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = coveredTherapy($counsellor);
    $member = activeRetainerMembership($counsellor, $user);

    $organization = GetRetainerCoveringOrganizationAction::new()->execute($therapy, $user);

    expect($organization)->not->toBeNull();
    expect($organization->id)->toBe($member->organization_id);
});

test('returns null for a pay-per-use org member', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = coveredTherapy($counsellor);
    $member = activeRetainerMembership($counsellor, $user);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
        'effective_from' => now()->addMinute(),
    ]);

    expect(GetRetainerCoveringOrganizationAction::new()->execute($therapy, $user))->toBeNull();
});

test('returns null when the therapy has no counsellor', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => null,
    ]);

    expect(GetRetainerCoveringOrganizationAction::new()->execute($therapy, $user))->toBeNull();
});

test('returns null when no retainer membership covers this specific counsellor', function () {
    $user = User::factory()->create();
    $coveredCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = coveredTherapy($unrelatedCounsellor);
    activeRetainerMembership($coveredCounsellor, $user);

    expect(GetRetainerCoveringOrganizationAction::new()->execute($therapy, $user))->toBeNull();
});
