<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Exceptions\CounsellorNotFoundException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\OrganizationService;

test('a counsellor sees their own organization affiliations across every status', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organizationA = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $organizationB = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);

    $pendingAffiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organizationA->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::pending->value,
    ]);
    $endedAffiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organizationB->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::ended->value,
    ]);

    $affiliations = OrganizationService::new()->getMyOrganizationCounsellorAffiliations($user);

    expect($affiliations->total())->toBe(2);
    expect($affiliations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$pendingAffiliation->id, $endedAffiliation->id])->sort()->values()->all());
});

test('a user with no counsellor account cannot list counsellor affiliations', function () {
    $user = User::factory()->create();

    expect(fn () => OrganizationService::new()->getMyOrganizationCounsellorAffiliations($user))
        ->toThrow(CounsellorNotFoundException::class);
});

test('a user sees their own organization memberships across every status', function () {
    $user = User::factory()->create();
    $organizationA = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $organizationB = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);

    $activeMembership = OrganizationMember::factory()->create([
        'organization_id' => $organizationA->id,
        'user_id' => $user->id,
        'status' => OrganizationMemberStatusEnum::active->value,
    ]);
    $endedMembership = OrganizationMember::factory()->create([
        'organization_id' => $organizationB->id,
        'user_id' => $user->id,
        'status' => OrganizationMemberStatusEnum::ended->value,
    ]);

    $memberships = OrganizationService::new()->getMyOrganizationMemberships($user);

    expect($memberships->total())->toBe(2);
    expect($memberships->pluck('id')->sort()->values()->all())
        ->toBe(collect([$activeMembership->id, $endedMembership->id])->sort()->values()->all());
});

test('a user with no memberships gets an empty list, not an error', function () {
    $user = User::factory()->create();

    $memberships = OrganizationService::new()->getMyOrganizationMemberships($user);

    expect($memberships->total())->toBe(0);
});

// administeredOrganizations() joins organization_admins, which also has its own created_at (via
// withTimestamps()) -- the action explicitly qualifies organizations.created_at rather than
// relying on Eloquent's current pivot-column-aliasing behavior staying that way forever (review
// verified this doesn't currently throw an ambiguous-column error against real MySQL, but that's
// an implementation detail, not a guarantee -- SCRUM-160). This test pins the actual behavior
// that matters: correct role-per-org data, ordered newest-administered-first.
test('a user sees the organizations they administer, ordered newest-first by organization', function () {
    $user = User::factory()->create();
    $organizationA = Organization::factory()->create(['is_provider' => true, 'verified_at' => now(), 'created_at' => now()->subDay()]);
    $organizationB = Organization::factory()->create(['is_provider' => true, 'verified_at' => now(), 'created_at' => now()]);
    $organizationA->admins()->attach($user->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $organizationB->admins()->attach($user->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    $organizations = OrganizationService::new()->getMyAdministeredOrganizations($user);

    expect($organizations->total())->toBe(2);
    expect($organizations->getCollection()->pluck('id')->all())->toBe([$organizationB->id, $organizationA->id]);
    $roles = $organizations->getCollection()->mapWithKeys(fn ($org) => [$org->id => $org->pivot->role]);
    expect($roles[$organizationA->id])->toBe(OrganizationAdminRoleEnum::owner->value);
    expect($roles[$organizationB->id])->toBe(OrganizationAdminRoleEnum::admin->value);
});

test('a user who administers no organization gets an empty administered list', function () {
    $user = User::factory()->create();

    $organizations = OrganizationService::new()->getMyAdministeredOrganizations($user);

    expect($organizations->total())->toBe(0);
});
