<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Http\Resources\RequestResource;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-208/TT-2.5c: exposing a session-schedule proposal's negotiation state through the
// generic RequestResource, and the Therapy page's new pendingSessionScheduleProposal Inertia prop.

function aCounsellorForScheduleProposalResourceRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aTherapyForScheduleProposalResourceRoute(Counsellor $counsellor, User $client): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value,
    ]);
}

test('RequestResource exposes a session schedule proposal\'s whitelisted fields', function () {
    $counsellor = aCounsellorForScheduleProposalResourceRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalResourceRoute($counsellor, $client);
    $proposal = Request::factory()->create([
        'from_type' => User::class,
        'from_id' => $client->id,
        'to_type' => Counsellor::class,
        'to_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'status' => RequestStatusEnum::pending->value,
        'round' => 1,
        'data' => [
            'startTime' => now()->addDay()->toDateTimeString(),
            'endTime' => now()->addDay()->addHour()->toDateTimeString(),
            'name' => 'Weekly check-in',
            'about' => 'Following up on last week.',
            'type' => 'ONLINE',
            'paymentType' => 'FREE',
            'staleReason' => null,
            'reason' => null,
            // Internal-only fields (security review, SCRUM-150 precedent) -- must never appear
            // in the resource output below.
            'proposedById' => $client->id,
            'sessionId' => 999,
        ],
    ]);

    $resource = (new RequestResource($proposal->fresh()))->resolve();

    expect($resource['proposal'])->toBe([
        'startTime' => $proposal->data['startTime'],
        'endTime' => $proposal->data['endTime'],
        'name' => 'Weekly check-in',
        'about' => 'Following up on last week.',
        'type' => 'ONLINE',
        'paymentType' => 'FREE',
        'staleReason' => null,
        'reason' => null,
    ]);
    expect($resource['round'])->toBe(1);
    expect($resource)->not->toHaveKey('proposedById');
    expect(json_encode($resource))->not->toContain('proposedById');
    expect(json_encode($resource))->not->toContain('999');
});

test('RequestResource omits the proposal field entirely for a non-schedule-proposal request', function () {
    $counsellor = aCounsellorForScheduleProposalResourceRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalResourceRoute($counsellor, $client);
    $assistanceRequest = Request::factory()->create([
        'from_type' => User::class,
        'from_id' => $client->id,
        'to_type' => Counsellor::class,
        'to_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => RequestTypeEnum::therapy->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    $resource = (new RequestResource($assistanceRequest))->resolve();

    expect($resource)->not->toHaveKey('proposal');
});

test('the therapy page exposes a pending session schedule proposal to both participants', function () {
    $counsellor = aCounsellorForScheduleProposalResourceRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalResourceRoute($counsellor, $client);
    $proposal = Request::factory()->create([
        'from_type' => User::class,
        'from_id' => $client->id,
        'to_type' => Counsellor::class,
        'to_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'status' => RequestStatusEnum::pending->value,
        'round' => 1,
        'data' => [
            'startTime' => now()->addDay()->toDateTimeString(),
            'endTime' => now()->addDay()->addHour()->toDateTimeString(),
        ],
    ]);

    $this->actingAs($client)
        ->get(route('therapies.get', ['therapyId' => $therapy->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Therapy/Index')
            ->where('pendingSessionScheduleProposal.id', $proposal->id)
        );

    $this->actingAs($counsellor->user)
        ->get(route('therapies.get', ['therapyId' => $therapy->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Therapy/Index')
            ->where('pendingSessionScheduleProposal.id', $proposal->id)
        );
});

test('the therapy page exposes no pending session schedule proposal when none exists', function () {
    $counsellor = aCounsellorForScheduleProposalResourceRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalResourceRoute($counsellor, $client);

    $this->actingAs($client)
        ->get(route('therapies.get', ['therapyId' => $therapy->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Therapy/Index')
            ->where('pendingSessionScheduleProposal', null)
        );
});

test('an accepted/rejected proposal is not exposed as pending on the therapy page', function () {
    $counsellor = aCounsellorForScheduleProposalResourceRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalResourceRoute($counsellor, $client);
    Request::factory()->create([
        'from_type' => User::class,
        'from_id' => $client->id,
        'to_type' => Counsellor::class,
        'to_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'status' => RequestStatusEnum::rejected->value,
        'round' => 1,
        'data' => [],
    ]);

    $this->actingAs($client)
        ->get(route('therapies.get', ['therapyId' => $therapy->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Therapy/Index')
            ->where('pendingSessionScheduleProposal', null)
        );
});
