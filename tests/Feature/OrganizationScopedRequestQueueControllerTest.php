<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;

// TT-6.6d (SCRUM-162) AC3: an org admin now sees their org's pending requests via the existing
// GET /requests endpoint.
test('an org admin sees their organization\'s pending counsellor application via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $request = Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $this->actingAs($owner);

    $response = $this->getJson('/api/requests');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($request->id);
});

// Security review (SCRUM-162): this ticket newly surfaces organizationMemberInvite/
// organizationMemberApplication rows -- whose from/to is an ordinary User the org admin has no
// other relationship with -- to the generic RequestResource via this endpoint. Reusing the full
// UserMiniResource for that User would reopen the exact PII-enumeration oracle SCRUM-124 already
// closed for OrganizationMemberController::invite()'s own response (an org admin could invite an
// arbitrary user id and read their gender/country/dob back here).
test('listing requests does not leak an invited/applying member\'s gender, country, or dob', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $invitedUser = User::factory()->create(['gender' => 'female', 'country' => 'GH', 'dob' => '1990-01-01']);

    Request::factory()->for($organization, 'from')->for($invitedUser, 'to')->create([
        'type' => RequestTypeEnum::organizationMemberInvite->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $this->actingAs($owner);

    $response = $this->getJson('/api/requests');

    $response->assertOk();
    $to = collect($response->json('data'))->firstWhere('type', RequestTypeEnum::organizationMemberInvite->value)['to'];
    expect($to)->toHaveKeys(['id', 'fullName', 'username']);
    expect(array_keys($to))->not->toContain('gender', 'country', 'dob');
});

// Security review (SCRUM-162): an admin of Organization A must never see Organization B's
// org-directed requests, even though both are matched by the same new orWhere block.
test('an admin of one organization does not see a different organization\'s pending requests', function () {
    $organizationA = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $organizationB = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $adminOfA = User::factory()->create();
    $organizationA->admins()->attach($adminOfA->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $requestForB = Request::factory()->for($counsellor, 'from')->for($organizationB, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $this->actingAs($adminOfA);

    $response = $this->getJson('/api/requests');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($requestForB->id);
});
