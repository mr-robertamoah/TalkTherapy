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
use App\Notifications\SessionScheduleProposalAcceptedNotification;
use App\Notifications\SessionScheduleProposalRejectedNotification;
use App\Notifications\SessionScheduleProposedNotification;
use Illuminate\Support\Facades\Notification;

// SCRUM-207/TT-2.5b: accepting/rejecting/countering a session schedule proposal. Accept re-runs
// the real session-creation validation against the CURRENT state (a slot valid at propose-time
// may be stale by accept-time) and, per the user's "Option C" decision, a stale proposal is
// neither auto-rejected nor surfaced as a raw error -- it stays pending with a distinguishable
// `staleReason`, leaving reject/counter-offer/reject-with-reason all still available.

function aCounsellorForScheduleProposalRespondRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aTherapyForScheduleProposalRespondRoute(Counsellor $counsellor, User $client, array $attributes = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value,
    ], $attributes));
}

function aPendingScheduleProposalForRespondRoute(Therapy $therapy, $from, $to, array $dataOverrides = []): Request
{
    return Request::factory()->create([
        'from_type' => $from::class,
        'from_id' => $from->id,
        'to_type' => $to::class,
        'to_id' => $to->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'status' => RequestStatusEnum::pending->value,
        'round' => 1,
        'data' => array_merge([
            'startTime' => now()->addDay()->toDateTimeString(),
            'endTime' => now()->addDay()->addHour()->toDateTimeString(),
            'name' => 'Weekly check-in',
            'about' => 'Following up on last week.',
            'type' => 'ONLINE',
            'paymentType' => 'FREE',
        ], $dataOverrides),
    ]);
}

test('the counsellor accepting a client proposal creates a real session', function () {
    Notification::fake();
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);

    $response = $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted']);

    $response->assertStatus(201);
    $this->assertDatabaseHas('requests', ['id' => $proposal->id, 'status' => RequestStatusEnum::accepted->value]);
    $this->assertDatabaseHas('sessions', [
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'name' => 'Weekly check-in',
    ]);
    Notification::assertSentTo($client, SessionScheduleProposalAcceptedNotification::class);
});

test('a proposal created without type/paymentType still accepts into a valid session (SCRUM-208 regression)', function () {
    // sessions.type/payment_type are both NOT NULL native enum columns -- a proposal made through
    // the real store endpoint without either (as ProposeSessionScheduleModal.vue's UI does for a
    // FREE, non-in-person therapy, since it doesn't render those selectors at all) must still
    // default to valid values at propose-time, or accept-time's CreateSessionAction call throws a
    // QueryException instead of creating the session. Found via live browser verification.
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);

    $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), [
            'startTime' => now()->addDay()->toDateTimeString(),
            'endTime' => now()->addDay()->addHour()->toDateTimeString(),
            'name' => 'Weekly check-in',
            'about' => 'No type or paymentType supplied.',
        ])
        ->assertOk();

    $proposal = Request::where('for_id', $therapy->id)->where('for_type', Therapy::class)->sole();

    $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted'])
        ->assertStatus(201);

    $this->assertDatabaseHas('sessions', [
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'payment_type' => 'FREE',
    ]);
});

test('the client accepting a counsellor counter-proposal creates a session with the counsellor as actor, not the client', function () {
    Notification::fake();
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    // counsellor -> client direction, as if the counsellor had countered
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $counsellor, $client, ['proposedById' => $counsellor->user->id]);

    $response = $this->actingAs($client)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted']);

    $response->assertStatus(201);
    $session = Session::where('for_id', $therapy->id)->where('for_type', Therapy::class)->first();
    expect($session)->not->toBeNull();
    // architect-flagged wiring fix: the session's addedby must always be the counsellor,
    // regardless of who actually clicked accept.
    expect($session->addedby_type)->toBe(Counsellor::class);
    expect($session->addedby_id)->toBe($counsellor->id);
});

test('accepting a proposal that is now stale does not auto-reject and does not create a session', function () {
    Notification::fake();
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);

    // A conflicting session gets created after the proposal was made, making it stale by accept
    // time -- overlapping the proposed start time directly so it trips
    // EnsureSessionDataIsValidAction's "start time falls within another session" check.
    Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'status' => SessionStatusEnum::held->value,
        'start_time' => now()->addDay()->subMinutes(10),
        'end_time' => now()->addDay()->addMinutes(20),
    ]);

    $response = $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted']);

    $response->assertStatus(201);
    $proposal->refresh();
    expect($proposal->status)->toBe(RequestStatusEnum::pending->value);
    expect($proposal->data['staleReason'] ?? null)->not->toBeNull();
    expect(Session::where('name', 'Weekly check-in')->exists())->toBeFalse();
    Notification::assertNotSentTo($client, SessionScheduleProposalAcceptedNotification::class);
});

test('accepting a proposal whose therapy no longer has an assigned counsellor stays pending with a stale reason', function () {
    Notification::fake();
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);

    $therapy->update(['counsellor_id' => null]);

    // The proposal's `to` is still the Counsellor row itself (unaffected by detaching it from the
    // therapy), so it -- not the client -- is who's allowed to respond here.
    $response = $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted']);

    $response->assertStatus(201);
    $proposal->refresh();
    expect($proposal->status)->toBe(RequestStatusEnum::pending->value);
    expect($proposal->data['staleReason'] ?? null)->not->toBeNull();
});

test('accepting an already-resolved proposal is rejected and does not create a duplicate session', function () {
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, [
        'proposedById' => $client->id,
    ]);
    $proposal->update(['status' => RequestStatusEnum::accepted->value]);

    // Generic pre-dispatch check (EnsureRequestIsStillPendingAction, SCRUM-171) rejects this
    // before AcceptSessionScheduleProposalAction's own idempotency guard is ever reached --
    // that guard only matters for a genuine concurrent-accept race under the row lock.
    $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted'])
        ->assertStatus(422);

    expect(Session::where('for_id', $therapy->id)->where('for_type', Therapy::class)->count())->toBe(0);
});

test('the counsellor can reject a client proposal outright', function () {
    Notification::fake();
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);

    $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'rejected'])
        ->assertStatus(201);

    $this->assertDatabaseHas('requests', ['id' => $proposal->id, 'status' => RequestStatusEnum::rejected->value]);
    Notification::assertSentTo($client, SessionScheduleProposalRejectedNotification::class);
});

test('rejecting with a reason stores it for the client to see', function () {
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);

    $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), [
            'response' => 'rejected',
            'reason' => 'Please propose a new time.',
        ])
        ->assertStatus(201);

    $proposal->refresh();
    expect($proposal->data['reason'])->toBe('Please propose a new time.');
});

test('rejecting with an overly long reason is rejected', function () {
    // security review (SCRUM-207): `reason` is a generic RequestResponseDTO field reached
    // straight from client input with no FormRequest in front of it -- EnsureRequestResponseReasonIsValidAction
    // bounds it before it can be persisted and emailed to the other party.
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);

    $this->actingAs($counsellor->user)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), [
            'response' => 'rejected',
            'reason' => str_repeat('a', 1001),
        ])
        ->assertStatus(422);

    $this->assertDatabaseHas('requests', ['id' => $proposal->id, 'status' => RequestStatusEnum::pending->value]);
});

test('the counsellor can counter-propose a different time, flipping the direction', function () {
    Notification::fake();
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);

    $response = $this->actingAs($counsellor->user)
        ->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $proposal->id]), [
            'startTime' => now()->addDays(2)->toDateTimeString(),
            'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
        ]);

    $response->assertOk();
    $this->assertDatabaseHas('requests', ['id' => $proposal->id, 'status' => RequestStatusEnum::rejected->value]);
    $this->assertDatabaseHas('requests', [
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'status' => RequestStatusEnum::pending->value,
        'round' => 2,
        'from_type' => Counsellor::class,
        'from_id' => $counsellor->id,
        'to_type' => User::class,
        'to_id' => $client->id,
    ]);
    Notification::assertSentTo($client, SessionScheduleProposedNotification::class);
});

test('a client cannot counter-offer a PAID therapy proposal down to FREE', function () {
    // Defense-in-depth (security review, SCRUM-208): the same payment-type-must-match-therapy
    // invariant enforced at propose-time also applies to a counter-offer, since it's the same
    // client-controlled field on the same underlying negotiation.
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client, ['payment_type' => 'PAID']);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $counsellor, $client, [
        'proposedById' => $counsellor->user->id,
        'paymentType' => 'PAID',
    ]);

    $response = $this->actingAs($client)
        ->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $proposal->id]), [
            'startTime' => now()->addDays(2)->toDateTimeString(),
            'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
            'paymentType' => 'FREE',
        ]);

    $response->assertStatus(422);
    $this->assertDatabaseHas('requests', ['id' => $proposal->id, 'status' => RequestStatusEnum::pending->value]);
});

test('a counter-offer past the round limit is rejected', function () {
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, [
        'proposedById' => $client->id,
    ]);
    $proposal->update(['round' => config('session_schedule_proposal.max_rounds')]);

    $this->actingAs($counsellor->user)
        ->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $proposal->id]), [
            'startTime' => now()->addDays(2)->toDateTimeString(),
            'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
        ])
        ->assertStatus(422);
});

test('a counter-offer on an already-resolved proposal is rejected', function () {
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);
    $proposal->update(['status' => RequestStatusEnum::rejected->value]);

    $this->actingAs($counsellor->user)
        ->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $proposal->id]), [
            'startTime' => now()->addDays(2)->toDateTimeString(),
            'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
        ])
        ->assertStatus(422);
});

test('counter-offering a request of a different type is rejected as not found', function () {
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $unrelatedRequest = Request::factory()->create([
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

    $this->actingAs($counsellor->user)
        ->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $unrelatedRequest->id]), [
            'startTime' => now()->addDays(2)->toDateTimeString(),
            'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
        ])
        ->assertStatus(422);
});

test('a party not addressed by the proposal cannot accept, reject, or counter it', function () {
    $counsellor = aCounsellorForScheduleProposalRespondRoute();
    $client = User::factory()->create();
    $therapy = aTherapyForScheduleProposalRespondRoute($counsellor, $client);
    $proposal = aPendingScheduleProposalForRespondRoute($therapy, $client, $counsellor, ['proposedById' => $client->id]);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted'])
        ->assertStatus(422);

    $this->actingAs($outsider)
        ->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $proposal->id]), [
            'startTime' => now()->addDays(2)->toDateTimeString(),
            'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
        ])
        ->assertStatus(422);
});
