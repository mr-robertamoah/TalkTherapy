<?php

use App\Actions\Organization\GetActiveOrganizationCounsellorAction;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\User;

// TT-7.3b-b0/SCRUM-232: the one reusable "does this org actively cover this counsellor, and
// what's the affiliation" lookup -- extracted for future consumers (TT-7.3b-b/-d/-j) so they
// don't each reimplement EnsureOrganizationCanPayForModelAction's own inline coverage check.

test('returns the active affiliation when one exists', function () {
    $organization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    $result = GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $organization);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($affiliation->id);
});

test('returns null when the affiliation is only pending, not active', function () {
    $organization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::pending->value,
    ]);

    expect(GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $organization))->toBeNull();
});

test('returns null when the affiliation has ended', function () {
    $organization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::ended->value,
    ]);

    expect(GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $organization))->toBeNull();
});

test('returns null when the counsellor has no relationship at all with this organization', function () {
    $organization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect(GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $organization))->toBeNull();
});

test('does not return an active affiliation belonging to a different organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $otherOrganization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    expect(GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $organization))->toBeNull();
});
