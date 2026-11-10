<?php

use App\DTOs\OrganizationDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\User;
use App\Services\OrganizationService;

function organizationWithOwnerForRequestQueue(): array
{
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $owner];
}

test('an org admin sees pending applications and invites addressed to their organization', function () {
    [$organization, $owner] = organizationWithOwnerForRequestQueue();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $application = Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);
    $invite = Request::factory()->for($organization, 'from')->for(User::factory()->create(), 'to')->create([
        'type' => RequestTypeEnum::organizationMemberInvite->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getOrganizationRequestQueue(
        OrganizationDTO::new()->fromArray(['user' => $owner, 'organization' => $organization])
    );

    expect($queue->pluck('id')->sort()->values()->all())
        ->toBe(collect([$application->id, $invite->id])->sort()->values()->all());
});

test('a compensation negotiation currently awaiting the organization appears in its queue', function () {
    [$organization, $owner] = organizationWithOwnerForRequestQueue();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    // A counter-offer FROM the counsellor TO the org -- currently awaiting the org's decision.
    $counterOffer = Request::factory()->for($counsellor, 'from')->for($organization, 'to')->for($affiliation, 'for')->create([
        'type' => RequestTypeEnum::organizationCounsellorCompensationChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getOrganizationRequestQueue(
        OrganizationDTO::new()->fromArray(['user' => $owner, 'organization' => $organization])
    );

    expect($queue->pluck('id')->all())->toContain($counterOffer->id);
});

test('a non-pending request does not appear in the queue', function () {
    [$organization, $owner] = organizationWithOwnerForRequestQueue();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $accepted = Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::accepted->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getOrganizationRequestQueue(
        OrganizationDTO::new()->fromArray(['user' => $owner, 'organization' => $organization])
    );

    expect($queue->pluck('id')->all())->not->toContain($accepted->id);
});

test('a request addressed to a different organization does not appear in this queue', function () {
    [$organizationA, $ownerA] = organizationWithOwnerForRequestQueue();
    [$organizationB] = organizationWithOwnerForRequestQueue();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $requestForB = Request::factory()->for($counsellor, 'from')->for($organizationB, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getOrganizationRequestQueue(
        OrganizationDTO::new()->fromArray(['user' => $ownerA, 'organization' => $organizationA])
    );

    expect($queue->pluck('id')->all())->not->toContain($requestForB->id);
});

test('a user with no admin relationship to the organization cannot view its request queue', function () {
    [$organization] = organizationWithOwnerForRequestQueue();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationService::new()->getOrganizationRequestQueue(
        OrganizationDTO::new()->fromArray(['user' => $outsider, 'organization' => $organization])
    ))->toThrow(OrganizationException::class);
});
