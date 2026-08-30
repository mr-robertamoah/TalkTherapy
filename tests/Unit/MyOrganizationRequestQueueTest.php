<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CounsellorNotFoundException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Services\OrganizationService;

test('a counsellor sees pending applications and invites addressed to them', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);

    $application = Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);
    $invite = Request::factory()->for($organization, 'from')->for($counsellor, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorInvite->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getMyOrganizationRequestQueue($user);

    expect($queue->pluck('id')->sort()->values()->all())
        ->toBe(collect([$application->id, $invite->id])->sort()->values()->all());
});

test('a compensation negotiation currently awaiting the counsellor appears in their queue', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    // A proposal FROM the org TO the counsellor -- currently awaiting the counsellor's decision.
    $proposal = Request::factory()->for($organization, 'from')->for($counsellor, 'to')->for($affiliation, 'for')->create([
        'type' => RequestTypeEnum::organizationCounsellorCompensationChange->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getMyOrganizationRequestQueue($user);

    expect($queue->pluck('id')->all())->toContain($proposal->id);
});

test('a non-pending request does not appear in the queue', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);

    $accepted = Request::factory()->for($counsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::accepted->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getMyOrganizationRequestQueue($user);

    expect($queue->pluck('id')->all())->not->toContain($accepted->id);
});

test('a request addressed to a different counsellor does not appear in this queue', function () {
    $user = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $otherCounsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);

    $requestForOther = Request::factory()->for($otherCounsellor, 'from')->for($organization, 'to')->create([
        'type' => RequestTypeEnum::organizationCounsellorApplication->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getMyOrganizationRequestQueue($user);

    expect($queue->pluck('id')->all())->not->toContain($requestForOther->id);
});

// Regression: unlike the Organization-scoped queue (Organization is exclusively used as a
// from/to party for org-context request types), a Counsellor is ALSO the polymorphic party for
// unrelated request types -- a naive "from/to is this counsellor" query without a type filter
// would leak these into the org-specific queue too.
test('a pending non-organization request addressed to the counsellor does not appear in this queue', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $therapy = Therapy::factory()->create();

    $assistanceRequest = Request::factory()->for(User::factory()->create(), 'from')->for($counsellor, 'to')->for($therapy, 'for')->create([
        'type' => RequestTypeEnum::therapy->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $queue = OrganizationService::new()->getMyOrganizationRequestQueue($user);

    expect($queue->pluck('id')->all())->not->toContain($assistanceRequest->id);
});

test('a user with no counsellor account gets a clean error, not an empty queue', function () {
    $user = User::factory()->create();

    expect(fn () => OrganizationService::new()->getMyOrganizationRequestQueue($user))
        ->toThrow(CounsellorNotFoundException::class);
});
