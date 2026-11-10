<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('an org admin can list the organization\'s members via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);
    OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);

    $this->actingAs($owner);

    $response = $this->getJson("/organizations/{$organization->id}/members");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.user.fullName'))->not->toBeNull();
});

// Security review (SCRUM-159): an org admin configuring billing has no legitimate need for a
// member's gender/country/dob -- OrganizationMemberResource must project a narrower user shape
// than the full UserMiniResource, mirroring the data-minimization call already made for
// invite() (SCRUM-124).
test('listing organization members does not leak the member\'s gender, country, or dob', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory()->create(['gender' => 'female', 'country' => 'GH', 'dob' => '1990-01-01']),
    ]);

    $this->actingAs($owner);

    $response = $this->getJson("/organizations/{$organization->id}/members");

    $response->assertOk();
    expect($response->json('data.0.user'))->toHaveKeys(['id', 'fullName', 'username']);
    expect(array_keys($response->json('data.0.user')))->not->toContain('gender', 'country', 'dob');
});

test('an org admin can list the organization\'s affiliated counsellors via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    $this->actingAs($owner);

    $response = $this->getJson("/organizations/{$organization->id}/counsellors");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.counsellor.username'))->not->toBeNull();
});

test('a user with no admin relationship to the organization cannot list its members via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $response = $this->getJson("/organizations/{$organization->id}/members");

    $response->assertStatus(403);
});

test('a user with no admin relationship to the organization cannot list its affiliated counsellors via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $response = $this->getJson("/organizations/{$organization->id}/counsellors");

    $response->assertStatus(403);
});

// SCRUM-170: a nonexistent organizationId and an existing org the caller isn't an admin of
// previously returned distinguishable statuses (404 vs 403), letting any authenticated user
// enumerate real organization IDs platform-wide. Both must now return the same status and
// message, across every admin-gated organization GET/PATCH endpoint sharing this guard.
test('a nonexistent organization and a real one the caller cannot administer return the same status', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $endpoints = [
        ['getJson', '/organizations/999999'],
        ['getJson', "/organizations/{$organization->id}"],
        ['getJson', '/organizations/999999/members'],
        ['getJson', "/organizations/{$organization->id}/members"],
        ['getJson', '/organizations/999999/counsellors'],
        ['getJson', "/organizations/{$organization->id}/counsellors"],
        ['getJson', '/organizations/999999/requests'],
        ['getJson', "/organizations/{$organization->id}/requests"],
    ];

    foreach (array_chunk($endpoints, 2) as [$fake, $real]) {
        $fakeResponse = $this->{$fake[0]}($fake[1]);
        $realResponse = $this->{$real[0]}($real[1]);

        expect($fakeResponse->getStatusCode())->toBe($realResponse->getStatusCode());
        expect($fakeResponse->json('message'))->toBe($realResponse->json('message'));
    }
});

test('updating a nonexistent organization and a real one the caller cannot administer return the same status', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $fakeResponse = $this->patchJson('/organizations/999999', ['description' => 'x']);
    $realResponse = $this->patchJson("/organizations/{$organization->id}", ['description' => 'x']);

    expect($fakeResponse->getStatusCode())->toBe($realResponse->getStatusCode());
    expect($fakeResponse->json('message'))->toBe($realResponse->json('message'));
});

test('a guest cannot list an organization\'s members or affiliated counsellors', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);

    $this->getJson("/organizations/{$organization->id}/members")->assertStatus(401);
    $this->getJson("/organizations/{$organization->id}/counsellors")->assertStatus(401);
});

// Guards against the exact N+1 class of bug the architect flagged for these two relations
// (mirroring Transaction::latestTransaction()'s pre-fix behaviour) -- one query for the page of
// affiliations, one for their latest compensation/billing-config, one for the related
// counsellor/user -- not one extra round-trip per row.
test('listing affiliated counsellors does not N+1 on latestCompensation', function () {
    $affiliateCounsellors = function (Organization $organization, int $count) {
        foreach (range(1, $count) as $i) {
            $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
            OrganizationCounsellor::factory()->create([
                'organization_id' => $organization->id,
                'counsellor_id' => $counsellor->id,
            ]);
        }
    };

    $queryCountFor = function (int $affiliationCount) use ($affiliateCounsellors) {
        $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
        $owner = User::factory()->create();
        $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
        $affiliateCounsellors($organization, $affiliationCount);

        $this->actingAs($owner);

        DB::enableQueryLog();
        $this->getJson("/organizations/{$organization->id}/counsellors")->assertOk();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
    };

    // Fixed overhead (auth, session, the paginated/eager-load queries themselves) is identical
    // regardless of row count -- only a genuine per-row N+1 would make this grow with the
    // affiliation count.
    expect($queryCountFor(10))->toBe($queryCountFor(2));
});
