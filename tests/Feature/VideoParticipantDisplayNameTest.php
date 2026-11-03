<?php

use App\Contracts\VideoProviderInterface;
use App\Enums\ConstantsEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Support\VideoParticipantKey;
use Illuminate\Support\Facades\DB;

// SCRUM-334: the call UI never trusts the provider-side name (a Daily participant can rename
// themselves), so it asks GET sessions.video.participants.show for each tile's label by participant
// key. Proves who may ask, that masking matches what the join minted, and that every refusal looks
// the same.

function vpdnFakeVideoProvider(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => "fake-room-{$videoSession->id}", 'meta' => []];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return ['token' => 'fake-token'];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

function vpdnTherapySession(bool $anonymous): array
{
    app()->instance(VideoProviderInterface::class, vpdnFakeVideoProvider());
    $client = User::factory()->adult()->create(['firstName' => 'Real', 'lastName' => 'Client']);
    $counsellorUser = User::factory()->create(['firstName' => 'Dana', 'lastName' => 'Counsellor']);
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'anonymous' => $anonymous,
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    return compact('client', 'counsellorUser', 'session');
}

function vpdnGroupSession(bool $memberAnonymous): array
{
    app()->instance(VideoProviderInterface::class, vpdnFakeVideoProvider());
    $creator = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create(['firstName' => 'Grace', 'lastName' => 'Counsellor']);
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
        'anonymous' => false,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create(['firstName' => 'Hidden', 'lastName' => 'Member']);
    $groupTherapy->users()->attach($member->id, ['anonymous' => $memberAnonymous]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    return compact('creator', 'counsellorUser', 'member', 'session');
}

function vpdnJoin($test, User $user, Session $session): void
{
    $test->actingAs($user)->postJson(route('sessions.video.join', ['sessionId' => $session->id]))->assertOk();
}

function vpdnLookup($test, User $viewer, Session $session, string $key)
{
    return $test->actingAs($viewer)->getJson(route('sessions.video.participants.show', [
        'sessionId' => $session->id,
        'participantKey' => $key,
    ]));
}

test('a counsellor in a 1:1 call sees an anonymous client masked, and the client sees the counsellor by name', function () {
    $data = vpdnTherapySession(anonymous: true);
    vpdnJoin($this, $data['client'], $data['session']);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);
    $clientKey = VideoParticipantKey::for($data['session']->id, $data['client']->id);
    $counsellorKey = VideoParticipantKey::for($data['session']->id, $data['counsellorUser']->id);

    // Exactly these two keys -- never a user id.
    vpdnLookup($this, $data['counsellorUser'], $data['session'], $clientKey)
        ->assertOk()
        ->assertExactJson(['key' => $clientKey, 'displayName' => ConstantsEnum::anonymousUserLabel->value]);

    vpdnLookup($this, $data['client'], $data['session'], $counsellorKey)
        ->assertOk()
        ->assertExactJson(['key' => $counsellorKey, 'displayName' => $data['counsellorUser']->name]);
});

test('a non-anonymous 1:1 client is shown by their real name', function () {
    $data = vpdnTherapySession(anonymous: false);
    vpdnJoin($this, $data['client'], $data['session']);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);

    vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['client']->id))
        ->assertOk()
        ->assertJson(['displayName' => $data['client']->name]);
});

test('an anonymous group member is masked for everyone in the call, while the counsellor is not', function () {
    $data = vpdnGroupSession(memberAnonymous: true);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);
    vpdnJoin($this, $data['member'], $data['session']);
    vpdnJoin($this, $data['creator'], $data['session']);
    $memberKey = VideoParticipantKey::for($data['session']->id, $data['member']->id);

    foreach ([$data['counsellorUser'], $data['creator']] as $viewer) {
        vpdnLookup($this, $viewer, $data['session'], $memberKey)
            ->assertOk()
            ->assertExactJson(['key' => $memberKey, 'displayName' => ConstantsEnum::anonymousUserLabel->value]);
    }

    vpdnLookup($this, $data['member'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['counsellorUser']->id))
        ->assertOk()
        ->assertJson(['displayName' => $data['counsellorUser']->name]);
});

test('a non-anonymous group member is shown by their real name', function () {
    $data = vpdnGroupSession(memberAnonymous: false);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);
    vpdnJoin($this, $data['member'], $data['session']);

    vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['member']->id))
        ->assertOk()
        ->assertJson(['displayName' => 'Hidden Member']);
});

test('an outsider, a participant not in the call, an unknown key and a non-joined user all get the identical response', function () {
    $data = vpdnGroupSession(memberAnonymous: true);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);
    vpdnJoin($this, $data['member'], $data['session']);
    $memberKey = VideoParticipantKey::for($data['session']->id, $data['member']->id);
    $outsider = User::factory()->adult()->create();

    $responses = [
        // A signed-in user with nothing to do with this therapy, asking about a real participant.
        vpdnLookup($this, $outsider, $data['session'], $memberKey),
        // A real participant of the therapy who hasn't joined the call.
        vpdnLookup($this, $data['creator'], $data['session'], $memberKey),
        // A viewer in the call asking about a forged key, a nonexistent user, and a real person who
        // never joined (the creator).
        vpdnLookup($this, $data['counsellorUser'], $data['session'], 'not-a-real-participant-key'),
        vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, User::max('id') + 1000)),
        vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['creator']->id)),
    ];

    foreach ($responses as $response) {
        $response->assertNotFound();
        expect($response->json())->toBe($responses[0]->json());
    }

    expect(json_encode($responses[0]->json()))->not->toContain('Hidden');
});

test('a viewer who has left the call can no longer look names up', function () {
    $data = vpdnTherapySession(anonymous: false);
    vpdnJoin($this, $data['client'], $data['session']);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);
    $this->actingAs($data['counsellorUser'])->postJson(route('sessions.video.leave', ['sessionId' => $data['session']->id]))->assertOk();

    vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['client']->id))
        ->assertNotFound();
});

test('a participant who joined an earlier, ended call of the session is not resolved in the current one', function () {
    $data = vpdnTherapySession(anonymous: false);
    vpdnJoin($this, $data['client'], $data['session']);
    $this->actingAs($data['client'])->postJson(route('sessions.video.end', ['sessionId' => $data['session']->id]))->assertOk();
    vpdnJoin($this, $data['counsellorUser'], $data['session']);

    vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['client']->id))
        ->assertNotFound();
});

test('an unauthenticated request cannot look a participant up', function () {
    $data = vpdnTherapySession(anonymous: false);

    $this->getJson(route('sessions.video.participants.show', [
        'sessionId' => $data['session']->id,
        'participantKey' => VideoParticipantKey::for($data['session']->id, $data['client']->id),
    ]))->assertUnauthorized();
});

test('security review: someone no longer a member of the group is shown as Anonymous, never by name', function () {
    $data = vpdnGroupSession(memberAnonymous: true);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);
    vpdnJoin($this, $data['member'], $data['session']);

    // Their membership (and with it their own anonymity flag) goes away while their join row stays.
    DB::table('group_therapy_user')->where('user_id', $data['member']->id)->delete();

    vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['member']->id))
        ->assertOk()
        ->assertJson(['displayName' => ConstantsEnum::anonymousUserLabel->value]);
});

test('the group\'s creator is still named (they belong to the group without a membership row)', function () {
    $data = vpdnGroupSession(memberAnonymous: false);
    vpdnJoin($this, $data['counsellorUser'], $data['session']);
    vpdnJoin($this, $data['creator'], $data['session']);

    vpdnLookup($this, $data['counsellorUser'], $data['session'], VideoParticipantKey::for($data['session']->id, $data['creator']->id))
        ->assertOk()
        ->assertJson(['displayName' => $data['creator']->name]);
});
