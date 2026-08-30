<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\User;

test('a counsellor can load their my-organizations dashboard', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create(['organization_id' => $organization->id, 'counsellor_id' => $counsellor->id]);
    Request::factory()->for($organization, 'from')->for($counsellor, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorInvite->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $this->actingAs($user);

    $response = $this->get(route('organizations.mine.dashboard'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Organization/MyOrganizations')
        ->has('affiliations.data', 1)
        ->has('requestQueue.data', 1)
    );
});

test('a user with no counsellor account is redirected home rather than seeing a raw error page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('organizations.mine.dashboard'));

    $response->assertRedirect(route('home'));
});

test('a guest is redirected to login', function () {
    $response = $this->get(route('organizations.mine.dashboard'));

    $response->assertRedirect(route('login'));
});

test('a counsellor can fetch their paginated request queue via the real JSON route', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $this->actingAs($user);

    $response = $this->getJson(route('organizations.mine.requests'));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

// Regression: /organizations/mine/dashboard must never be captured by the
// /organizations/{organizationId}/dashboard route (organizationId="mine") -- caught during
// development when this route was registered after organizations.dashboard instead of before it.
test('the my-organizations dashboard route does not collide with the organizations/{organizationId}/dashboard route', function () {
    $user = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $this->actingAs($user);

    $response = $this->get(route('organizations.mine.dashboard'));

    $response->assertOk();
});

test('a counsellor can apply to a verified provider organization from the directory', function () {
    $user = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);

    $this->actingAs($user);

    $response = $this->postJson(route('organizations.counsellors.apply', ['organizationId' => $organization->id]));

    $response->assertOk();
    $this->assertDatabaseHas('requests', [
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'to_id' => $organization->id,
        'to_type' => Organization::class,
    ]);
});
