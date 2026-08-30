<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\User;

test('an org admin can load the dashboard for a provider-and-consumer organization', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create(['organization_id' => $organization->id, 'counsellor_id' => $counsellor->id]);
    OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => User::factory()]);
    Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.dashboard', ['organizationId' => $organization->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Organization/Show')
        ->where('organization.id', $organization->id)
        ->has('counsellors.data', 1)
        ->has('members.data', 1)
        ->has('requestQueue.data', 1)
        ->has('admins', 1)
    );
});

// SCRUM-166 (TT-6.5a2): the admin list is unpaginated (plain array), unlike the lists above --
// pins that both roles surface correctly for the frontend's owner-only action gating.
test('the dashboard includes every admin with their role', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.dashboard', ['organizationId' => $organization->id]));

    $response->assertOk()->assertInertia(function ($page) use ($owner, $plainAdmin) {
        $page->has('admins', 2);

        $admins = collect($page->toArray()['props']['admins']);
        expect($admins->firstWhere('id', $owner->id)['role'])->toBe(OrganizationAdminRoleEnum::owner->value);
        expect($admins->firstWhere('id', $plainAdmin->id)['role'])->toBe(OrganizationAdminRoleEnum::admin->value);
    });
});

test('the dashboard omits the counsellors section for a consumer-only organization', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.dashboard', ['organizationId' => $organization->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Organization/Show')
        ->where('counsellors', null)
        ->has('members.data', 0)
    );
});

test('a non-admin is redirected home rather than seeing a raw error page', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->get(route('organizations.dashboard', ['organizationId' => $organization->id]));

    $response->assertRedirect(route('home'));
});

// A nonexistent organizationId (404 from EnsureOrganizationExistsAction) is a distinct code
// path from the "authenticated non-admin" (403) case above, through the same generic catch.
test('a nonexistent organization redirects home rather than a raw error page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('organizations.dashboard', ['organizationId' => 999999]));

    $response->assertRedirect(route('home'));
});

// Security review (SCRUM-165): distinct from a total outsider -- an admin of a DIFFERENT
// organization must not see this one's dashboard just by being *some* org's admin.
// isAdministeredBy() scopes to the routed organization specifically, not "any org the user
// administers" -- this pins that scoping against regression.
test('an admin of a different organization is redirected home, not shown this one\'s dashboard', function () {
    $organizationA = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $organizationB = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $adminOfB = User::factory()->create();
    $organizationB->admins()->attach($adminOfB->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($adminOfB);

    $response = $this->get(route('organizations.dashboard', ['organizationId' => $organizationA->id]));

    $response->assertRedirect(route('home'));
});

test('a guest is redirected to login', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);

    $response = $this->get(route('organizations.dashboard', ['organizationId' => $organization->id]));

    $response->assertRedirect(route('login'));
});

test('an org admin can fetch the paginated request queue via the real JSON route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
    Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $this->actingAs($owner);

    $response = $this->getJson(route('organizations.requests.index', ['organizationId' => $organization->id]));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});
