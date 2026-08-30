<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a counsellor can list their own organization affiliations via the real route', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    $this->actingAs($user);

    $response = $this->getJson('/organizations/mine/counsellor-affiliations');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.organization.id'))->toBe($organization->id);
});

test('a user with no counsellor account gets a clean error from the counsellor-affiliations route', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson('/organizations/mine/counsellor-affiliations');

    $response->assertStatus(422);
});

test('a user can list their own organization memberships via the real route', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);

    $response = $this->getJson('/organizations/mine/memberships');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.organization.id'))->toBe($organization->id);
});

test('a user can list the organizations they administer via the real route', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $organization->admins()->attach($user->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($user);

    $response = $this->getJson('/organizations/mine/administered');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.role'))->toBe(OrganizationAdminRoleEnum::owner->value);
});

test('a guest cannot access any of the my-organizations routes', function () {
    $this->getJson('/organizations/mine/counsellor-affiliations')->assertStatus(401);
    $this->getJson('/organizations/mine/memberships')->assertStatus(401);
    $this->getJson('/organizations/mine/administered')->assertStatus(401);
});

// Regression: /organizations/mine/... must never be captured by the /organizations/{organizationId}
// route (a numeric-id lookup for a literal "mine" would 404/error, not silently misroute).
test('the my-organizations routes do not collide with the organizations/{organizationId} route', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson('/organizations/mine/memberships');

    $response->assertOk();
});

// N+1 regression guard, mirroring OrganizationScopedListsControllerTest's equivalent for the
// admin-scoped lists (SCRUM-159): query count must not grow with the number of affiliations.
test('listing my counsellor affiliations does not N+1 on latestCompensation', function () {
    $affiliateToOrgs = function (User $user, int $count) {
        $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
        foreach (range(1, $count) as $i) {
            $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
            OrganizationCounsellor::factory()->create([
                'organization_id' => $organization->id,
                'counsellor_id' => $counsellor->id,
            ]);
        }
    };

    $queryCountFor = function (int $affiliationCount) use ($affiliateToOrgs) {
        $user = User::factory()->create();
        $affiliateToOrgs($user, $affiliationCount);

        $this->actingAs($user);

        DB::enableQueryLog();
        $this->getJson('/organizations/mine/counsellor-affiliations')->assertOk();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
    };

    expect($queryCountFor(10))->toBe($queryCountFor(2));
});
