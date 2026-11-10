<?php

use App\DTOs\OrganizationDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Exceptions\OrganizationException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\User;
use App\Services\OrganizationService;

function scopedListsOrganizationWithOwner(): array
{
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $owner];
}

test('an org admin can list the organization\'s members', function () {
    [$organization, $owner] = scopedListsOrganizationWithOwner();

    $memberA = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);
    $memberB = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);

    $members = OrganizationService::new()->getOrganizationMembers(
        OrganizationDTO::new()->fromArray(['user' => $owner, 'organization' => $organization])
    );

    expect($members->total())->toBe(2);
    expect($members->pluck('id')->sort()->values()->all())->toBe(collect([$memberA->id, $memberB->id])->sort()->values()->all());
});

test('an org admin can list the organization\'s affiliated counsellors', function () {
    [$organization, $owner] = scopedListsOrganizationWithOwner();

    $counsellorA = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
    $affiliationA = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellorA->id,
    ]);

    $counsellors = OrganizationService::new()->getOrganizationCounsellors(
        OrganizationDTO::new()->fromArray(['user' => $owner, 'organization' => $organization])
    );

    expect($counsellors->total())->toBe(1);
    expect($counsellors->first()->id)->toBe($affiliationA->id);
});

test('a user with no admin relationship to the organization cannot list its members', function () {
    [$organization] = scopedListsOrganizationWithOwner();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationService::new()->getOrganizationMembers(
        OrganizationDTO::new()->fromArray(['user' => $outsider, 'organization' => $organization])
    ))->toThrow(OrganizationException::class);
});

test('a user with no admin relationship to the organization cannot list its affiliated counsellors', function () {
    [$organization] = scopedListsOrganizationWithOwner();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationService::new()->getOrganizationCounsellors(
        OrganizationDTO::new()->fromArray(['user' => $outsider, 'organization' => $organization])
    ))->toThrow(OrganizationException::class);
});

// SCRUM-159 AC4: currentCompensation()/currentBillingConfig() must keep returning the latest row
// by effective_from (tie-broken by id), now that they're backed by an ofMany() relation rather
// than an ordered query, per the same guarantee CreateOrganizationCounsellorCompensationActionTest
// already proves at the write side.
test('currentCompensation reflects the latest by effective_from once backed by an ofMany relation', function () {
    [$organization] = scopedListsOrganizationWithOwner();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    OrganizationCounsellorCompensation::factory()->create([
        'organization_counsellor_id' => $affiliation->id,
        'amount' => 1000,
        'effective_from' => now()->subDay(),
    ]);
    $latest = OrganizationCounsellorCompensation::factory()->create([
        'organization_counsellor_id' => $affiliation->id,
        'amount' => 2000,
        'effective_from' => now(),
    ]);

    expect($affiliation->currentCompensation()->id)->toBe($latest->id);
    expect($affiliation->currentCompensation()->amount)->toBe(2000);
});

test('currentBillingConfig reflects the latest by effective_from once backed by an ofMany relation', function () {
    [$organization] = scopedListsOrganizationWithOwner();
    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);

    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'effective_from' => now()->subDay(),
    ]);
    $latest = OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'effective_from' => now(),
    ]);

    expect($member->currentBillingConfig()->id)->toBe($latest->id);
});
