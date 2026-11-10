<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;
use App\Services\RequestService;

// TT-6.6d (SCRUM-162): org-directed requests address to/from as the Organization itself, not the
// admin -- an org admin previously had no way to list "pending for my org" via getRequests().
test('an org admin sees a pending counsellor application addressed to their organization', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $request = Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $resources = RequestService::new()->getRequests('', $owner);

    expect($resources->collection)->toHaveCount(1);
    expect($resources->collection->first()->resource->id)->toBe($request->id);
});

test('an org admin sees a pending member invite sent from their organization', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $invitedUser = User::factory()->create();

    $request = Request::factory()->for($organization, 'from')->for($invitedUser, 'to')->create([
        'type' => RequestTypeEnum::organizationMemberInvite->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $resources = RequestService::new()->getRequests('', $owner);

    expect($resources->collection->pluck('resource.id'))->toContain($request->id);
});

test('a user who admins no organization sees no org-directed requests, and their own requests are unaffected', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $outsider = User::factory()->create();
    $ownRequest = Request::factory()->for($outsider, 'from')->for($outsider, 'to')->create([
        'type' => RequestTypeEnum::guardianship->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $resources = RequestService::new()->getRequests('', $outsider);

    expect($resources->collection)->toHaveCount(1);
    expect($resources->collection->first()->resource->id)->toBe($ownRequest->id);
});

// The existing counsellor-side matching (whereFrom($counsellor)/whereTo($counsellor)) must keep
// working unchanged alongside the new organization-side orWhere block. Neither party here is a
// User model directly, so this can only be matched by the counsellor-specific block.
test('an admin who is also a counsellor still sees requests addressed to their counsellor profile', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $adminUser = User::factory()->create();
    $organization->admins()->attach($adminUser->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => $adminUser->id, 'verified_at' => now()]);
    $otherCounsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $counsellorRequest = Request::factory()->for($otherCounsellor, 'from')->for($counsellor, 'to')->create([
        'type' => RequestTypeEnum::guardianship->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $resources = RequestService::new()->getRequests('', $adminUser);

    expect($resources->collection->pluck('resource.id'))->toContain($counsellorRequest->id);
});
