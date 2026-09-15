<?php

use App\Contracts\VideoProviderInterface;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;

// TT-3.1b/SCRUM-275: the first HTTP-reachable surface for TT-3.1's video backend -- proves the
// route/controller wiring itself (auth, participant/payment-gate authorization, response shape),
// not the underlying actions' own logic, which the Unit test files already cover exhaustively.

function fakeVideoProviderForRouteTest(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => "fake-room-{$videoSession->id}", 'meta' => ['fake' => true]];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false): array
        {
            return ['token' => "fake-token-{$user->id}"];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

function onlineInSessionTherapySessionForVideoRoute(array $therapyOverrides = []): array
{
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ], $therapyOverrides));
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    return compact('client', 'counsellorUser', 'counsellor', 'therapy', 'session');
}

test('an unauthenticated request cannot join video', function () {
    $data = onlineInSessionTherapySessionForVideoRoute();

    $response = $this->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response->assertStatus(401);
});

test('a participant can join video and receives the provider\'s credentials', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $data = onlineInSessionTherapySessionForVideoRoute();

    $response = $this
        ->actingAs($data['client'])
        ->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response->assertOk()->assertJson(['token' => "fake-token-{$data['client']->id}"]);
});

test('a non-participant cannot join video', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $data = onlineInSessionTherapySessionForVideoRoute();
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response->assertStatus(422);
});

test('a client blocked by the strict payment gate cannot join video over the route either', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $data = onlineInSessionTherapySessionForVideoRoute([
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);

    $response = $this
        ->actingAs($data['client'])
        ->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response->assertStatus(402);
});

// TT-3.2a/SCRUM-308: EnsureUserCanAccessTherapyContentAction was already widened by the completed
// TT-7.5b epic to gate a GroupTherapy member (including its own creator) generically -- this
// proves that already-existing widening correctly reaches group video too, once
// EnsureVideoIsAvailableForSessionAction's own new GroupTherapy branch (also TT-3.2a) lets a
// creator through far enough to reach this check. Requires zero new payment code.
test('a strict-gated GroupTherapy\'s own creator cannot join video without having paid, over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'strictPaymentGate' => true],
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    $response = $this
        ->actingAs($creator)
        ->postJson(route('sessions.video.join', ['sessionId' => $session->id]));

    $response->assertStatus(402);
});

// TT-3.1e-f/SCRUM-285 (review finding): the new videoConsentRequired JSON flag was previously
// untested at the actual HTTP-route level -- only the underlying Action's own exception type was
// covered by a unit test. Proves the real join route surfaces the flag correctly, and that an
// UNRELATED failure (here, the strict-payment-gate 402 above) never sets it.
test('joining as a minor client with no video consent returns videoConsentRequired: true', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $minorClient = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minorClient->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    $response = $this
        ->actingAs($minorClient)
        ->postJson(route('sessions.video.join', ['sessionId' => $session->id]));

    $response->assertStatus(422)->assertJson([
        'videoConsentRequired' => true,
        'message' => 'Guardian video consent is required before this account can join video for this session.',
    ]);
});

test('a non-participant\'s denial does not set videoConsentRequired', function () {
    $data = onlineInSessionTherapySessionForVideoRoute();
    $unrelatedUser = User::factory()->create();

    $response = $this
        ->actingAs($unrelatedUser)
        ->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response->assertJson(['videoConsentRequired' => false]);
});

test('joining a session that does not exist returns a clean 422, not a server error', function () {
    $response = $this
        ->actingAs(User::factory()->create())
        ->postJson(route('sessions.video.join', ['sessionId' => 999999]));

    $response->assertStatus(422);
});

test('a participant can leave the video call over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $data = onlineInSessionTherapySessionForVideoRoute();
    $this->actingAs($data['client'])->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response = $this
        ->actingAs($data['client'])
        ->postJson(route('sessions.video.leave', ['sessionId' => $data['session']->id]));

    $response->assertOk();
});

test('a non-participant cannot leave the video call over the route', function () {
    $data = onlineInSessionTherapySessionForVideoRoute();
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->postJson(route('sessions.video.leave', ['sessionId' => $data['session']->id]));

    $response->assertStatus(422);
});

test('a participant can end the video call for everyone over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $data = onlineInSessionTherapySessionForVideoRoute();
    $this->actingAs($data['client'])->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response = $this
        ->actingAs($data['client'])
        ->postJson(route('sessions.video.end', ['sessionId' => $data['session']->id]));

    $response->assertOk();
    expect(VideoSession::query()->where('session_id', $data['session']->id)->first()->ended_at)->not->toBeNull();
});

test('a non-participant cannot end the video call over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $data = onlineInSessionTherapySessionForVideoRoute();
    $this->actingAs($data['client'])->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->postJson(route('sessions.video.end', ['sessionId' => $data['session']->id]));

    $response->assertStatus(422);
    expect(VideoSession::query()->where('session_id', $data['session']->id)->first()->ended_at)->toBeNull();
});

// TT-3.2d/SCRUM-311 security-review finding (found while reviewing TT-3.2a, SCRUM-308): proves
// the counsellor-only restriction over the REAL route, not just at the unit level -- an ordinary
// group member is a legitimate Session participant (so the generic isNotParticipant() check
// alone would let them through) but must still be denied ending the call for everyone.
test('an ordinary GroupTherapy member cannot end the group video call for everyone over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', ['sessionId' => $session->id]));

    $response = $this
        ->actingAs($member)
        ->postJson(route('sessions.video.end', ['sessionId' => $session->id]));

    $response->assertStatus(422);
    expect(VideoSession::query()->where('session_id', $session->id)->first()->ended_at)->toBeNull();
});

test('an active counsellor CAN end the group video call for everyone over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', ['sessionId' => $session->id]));

    $response = $this
        ->actingAs($counsellorUser)
        ->postJson(route('sessions.video.end', ['sessionId' => $session->id]));

    $response->assertOk();
    expect(VideoSession::query()->where('session_id', $session->id)->first()->ended_at)->not->toBeNull();
});

// TT-3.2b/SCRUM-309: a counsellor can eject a specific participant over the route without ending
// the room for everyone else. Targets the group's own creator here (an ordinary member is denied
// JOIN entirely by TT-3.2a's own allow-list, so it can never actually become a video participant
// to remove in the first place).
test('an active counsellor can remove the group creator from the group video call over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', ['sessionId' => $session->id]));
    $this->actingAs($creator)->postJson(route('sessions.video.join', ['sessionId' => $session->id]));

    $response = $this
        ->actingAs($counsellorUser)
        ->postJson(route('sessions.video.participants.remove', ['sessionId' => $session->id, 'userId' => $creator->id]));

    $response->assertOk();
    $videoSession = VideoSession::query()->where('session_id', $session->id)->first();
    expect($videoSession->participants()->where('participant_id', $creator->id)->first()->left_at)->not->toBeNull();
});

test('an ordinary GroupTherapy member cannot remove anyone from the group video call over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', ['sessionId' => $session->id]));

    $response = $this
        ->actingAs($member)
        ->postJson(route('sessions.video.participants.remove', ['sessionId' => $session->id, 'userId' => $counsellorUser->id]));

    $response->assertStatus(422);
    $videoSession = VideoSession::query()->where('session_id', $session->id)->first();
    expect($videoSession->participants()->where('participant_id', $counsellorUser->id)->first()->left_at)->toBeNull();
});

// TT-3.2b/SCRUM-309 security-review finding: a target user id that doesn't exist at all must get
// the exact same response as one that exists but simply isn't a live participant of this call --
// otherwise a distinguishable response (e.g. a 422 "not found" vs. a 200 no-op) would hand any
// counsellor a system-wide user-id-existence oracle, unrelated to this group/session at all.
test('removing a nonexistent target user id gets the identical response as removing a real but non-participant user id', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $realButUnrelatedUser = User::factory()->create();
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', ['sessionId' => $session->id]));
    $nonexistentUserId = User::max('id') + 1000;

    $responseForNonexistentId = $this
        ->actingAs($counsellorUser)
        ->postJson(route('sessions.video.participants.remove', ['sessionId' => $session->id, 'userId' => $nonexistentUserId]));

    $responseForRealUnrelatedId = $this
        ->actingAs($counsellorUser)
        ->postJson(route('sessions.video.participants.remove', ['sessionId' => $session->id, 'userId' => $realButUnrelatedUser->id]));

    $responseForNonexistentId->assertOk();
    $responseForRealUnrelatedId->assertOk();
    expect($responseForNonexistentId->json())->toBe($responseForRealUnrelatedId->json());
});

// QA finding (route/unit parity gap): the action-level unit test already covers this, but the
// route itself had no dedicated coverage -- added for parity with the other authorization cases
// on this same route.
test('a counsellor cannot remove themselves over the route -- must use leave instead', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', ['sessionId' => $session->id]));

    $response = $this
        ->actingAs($counsellorUser)
        ->postJson(route('sessions.video.participants.remove', ['sessionId' => $session->id, 'userId' => $counsellorUser->id]));

    $response->assertStatus(422);
});

test('removal is not available for a 1:1 Therapy video session over the route', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRouteTest());
    $data = onlineInSessionTherapySessionForVideoRoute();
    $this->actingAs($data['client'])->postJson(route('sessions.video.join', ['sessionId' => $data['session']->id]));

    $response = $this
        ->actingAs($data['counsellorUser'])
        ->postJson(route('sessions.video.participants.remove', ['sessionId' => $data['session']->id, 'userId' => $data['client']->id]));

    $response->assertStatus(422);
});
