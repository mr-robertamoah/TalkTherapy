<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-206/TT-2.5a: a client or counsellor proposing a session day/time for a Therapy creates a
// pending Request only -- no Session row exists until it's accepted (TT-2.5b). Either participant
// may propose; from/to always resolve to one User (the client) and one Counsellor, whichever the
// acting party isn't.

function aCounsellorForSessionScheduleProposalRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aTherapyForSessionScheduleProposalRoute(Counsellor $counsellor, User $client, array $attributes = []): Therapy
{
    // status defaults to in_session (a matched, ongoing therapy) rather than pending -- pending
    // + an assigned counsellor_id is an impossible combination via the real assistance-request
    // flow (RespondToTherapyAssistanceRequestAction only ever sets both together, per security
    // review, SCRUM-206).
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value,
    ], $attributes));
}

function aValidProposalPayload(): array
{
    return [
        'startTime' => now()->addDay()->toDateTimeString(),
        'endTime' => now()->addDay()->addHour()->toDateTimeString(),
        'name' => 'Weekly check-in',
        'about' => 'Following up on last week.',
        'type' => 'ONLINE',
        'paymentType' => 'FREE',
    ];
}

test('the client can propose a session schedule for their own therapy', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);

    $response = $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload());

    $response->assertOk();
    $this->assertDatabaseHas('requests', [
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'status' => RequestStatusEnum::pending->value,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'from_type' => User::class,
        'from_id' => $client->id,
        'to_type' => Counsellor::class,
        'to_id' => $counsellor->id,
    ]);
});

test('the assigned counsellor can also propose a session schedule', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);

    $response = $this->actingAs($counsellor->user)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload());

    $response->assertOk();
    $this->assertDatabaseHas('requests', [
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'from_type' => Counsellor::class,
        'from_id' => $counsellor->id,
        'to_type' => User::class,
        'to_id' => $client->id,
    ]);
});

test('a non-participant cannot propose a session schedule', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client, ['name' => 'Private Anonymous Therapy']);
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload());

    $response->assertStatus(422);
    $this->assertDatabaseMissing('requests', ['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    // security review (SCRUM-206): a non-participant must never learn a private/anonymous
    // therapy's name via the rejection message -- any authenticated user can hit this endpoint
    // with any therapyId, so this message is reachable by someone with no right to that data.
    expect($response->json('message'))->not->toContain($therapy->name);
});

test('a session schedule cannot be proposed for a therapy with no assigned counsellor', function () {
    // security review (SCRUM-206): without this check, `to` would resolve to null and
    // EnsureNoPendingSessionScheduleProposalAction would then permanently block any future
    // legitimate proposal for this therapy.
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => null,
        'status' => TherapyStatusEnum::pending->value,
    ]);

    $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload())
        ->assertStatus(422);

    $this->assertDatabaseMissing('requests', ['for_id' => $therapy->id, 'for_type' => Therapy::class]);
});

test('a session schedule cannot be proposed for an ended therapy', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client, ['status' => TherapyStatusEnum::ended->value]);

    $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload())
        ->assertStatus(422);
});

test('a session schedule cannot be proposed while the therapy already has an active session', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);
    Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'status' => SessionStatusEnum::in_session->value,
        'start_time' => now()->subMinutes(10),
        'end_time' => now()->addHour(),
    ]);

    $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload())
        ->assertStatus(422);
});

test('a proposal with a start time in the past is rejected', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);

    $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), array_merge(
            aValidProposalPayload(),
            ['startTime' => now()->subDay()->toDateTimeString(), 'endTime' => now()->subDay()->addHour()->toDateTimeString()]
        ))
        ->assertStatus(422);
});

test('a proposal with less than a 30 minute gap is rejected', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);

    $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), array_merge(
            aValidProposalPayload(),
            ['startTime' => now()->addDay()->toDateTimeString(), 'endTime' => now()->addDay()->addMinutes(10)->toDateTimeString()]
        ))
        ->assertStatus(422);
});

test('a second proposal cannot be created while one is already pending for the same therapy', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);

    $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload())
        ->assertOk();

    $this->actingAs($counsellor->user)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload())
        ->assertStatus(422);

    expect(Request::where('for_id', $therapy->id)->where('for_type', Therapy::class)->count())->toBe(1);
});

test('an unauthenticated request cannot propose a session schedule', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);

    $this->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload())
        ->assertUnauthorized();
});

test('the created proposal resource exposes the therapy, from, and to correctly', function () {
    $counsellor = aCounsellorForSessionScheduleProposalRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForSessionScheduleProposalRoute($counsellor, $client);

    $response = $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), aValidProposalPayload());

    $response->assertOk();
    $response->assertJsonPath('proposal.for.id', $therapy->id);
    $response->assertJsonPath('proposal.from.id', $client->id);
    $response->assertJsonPath('proposal.to.id', $counsellor->id);
    $response->assertJsonPath('proposal.status', RequestStatusEnum::pending->value);
});
