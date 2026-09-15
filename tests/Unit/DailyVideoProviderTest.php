<?php

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Services\Daily\DailyClient;
use App\Services\Daily\DailyVideoProvider;

// TT-3.1a/SCRUM-274: DailyClient is mocked throughout -- this file pins down DailyVideoProvider's
// own request-shaping/response-mapping logic, independent of Daily's actual HTTP API.

test('createRoom names the room deterministically from the VideoSession id and returns the normalized shape', function () {
    $session = Session::factory()->create(['end_time' => now()->addHour()]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider' => 'daily']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('createRoom')
        ->once()
        ->with(Mockery::on(function ($data) use ($videoSession) {
            return $data['name'] === "session-{$videoSession->session_id}-{$videoSession->id}"
                && $data['privacy'] === 'private'
                && $data['properties']['max_participants'] === 2
                && $data['properties']['enable_chat'] === false;
        }))
        ->andReturn(['name' => "session-{$videoSession->session_id}-{$videoSession->id}", 'url' => 'https://example.daily.co/room']);

    $result = (new DailyVideoProvider($client))->createRoom($videoSession);

    expect($result['room_id'])->toBe("session-{$videoSession->session_id}-{$videoSession->id}")
        ->and($result['meta'])->toBe(['url' => 'https://example.daily.co/room']);
});

// TT-3.2a/SCRUM-308 (architect finding): the cap must be computed per session type, not a single
// flat value -- 1:1 Therapy stays capped near 2 (covered by the test above, whose default Session
// factory `for_type` is Therapy), while GroupTherapy needs headroom for the counsellor team plus
// its members (TT-3.2f/SCRUM-318 raised this to 25, unified with the group's own membership cap).
test('createRoom uses the larger GroupTherapy cap, not the 1:1 Therapy cap, for a GroupTherapy-backed session', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'end_time' => now()->addHour(),
    ]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider' => 'daily']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('createRoom')
        ->once()
        ->with(Mockery::on(fn ($data) => $data['properties']['max_participants'] === 25))
        ->andReturn(['name' => 'room-name', 'url' => 'https://example.daily.co/room']);

    (new DailyVideoProvider($client))->createRoom($videoSession);
});

test('createParticipantCredentials mints a token scoped to the room and this user, returning the room url alongside it', function () {
    $user = User::factory()->create(['firstName' => 'Ada', 'lastName' => 'Client']);
    $session = Session::factory()->create(['end_time' => now()->addHour()]);
    $videoSession = VideoSession::factory()->create([
        'session_id' => $session->id,
        'provider_room_id' => 'session-1-1',
        'provider_meta' => ['url' => 'https://example.daily.co/room'],
    ]);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('createMeetingToken')
        ->once()
        ->with(Mockery::on(function ($properties) use ($user) {
            // Deliberately checks against the passed-in display name, NOT $user->name -- proves
            // this adapter never derives the on-screen label from the user itself (security-review
            // finding, 2026-09-11: an anonymous therapy's real name must never reach Daily's API).
            return $properties['room_name'] === 'session-1-1'
                && $properties['user_name'] === 'Anonymous Display Name'
                && $properties['user_id'] === (string) $user->id
                && $properties['is_owner'] === true
                // TT-3.2f-d/SCRUM-321: a full (non-receive-only) participant gets no `permissions`
                // key at all -- never present, not merely a permissive value.
                && ! array_key_exists('permissions', $properties);
        }))
        ->andReturn(['token' => 'jwt-token-value']);

    $result = (new DailyVideoProvider($client))->createParticipantCredentials($videoSession, $user, 'Anonymous Display Name', true);

    expect($result)->toBe(['url' => 'https://example.daily.co/room', 'token' => 'jwt-token-value']);
});

// TT-3.2f-d/SCRUM-321: a receive-only participant's own meeting token is server-enforced --
// Daily's own `permissions.canSend: false` means the browser cannot publish audio/video even if
// it tries, regardless of any client-side UI state.
test('createParticipantCredentials mints a receive-only token with canSend false when requested', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create([
        'provider_room_id' => 'session-1-1',
        'provider_meta' => ['url' => 'https://example.daily.co/room'],
    ]);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('createMeetingToken')
        ->once()
        ->with(Mockery::on(fn ($properties) => ($properties['permissions']['canSend'] ?? null) === false))
        ->andReturn(['token' => 'jwt-token-value']);

    (new DailyVideoProvider($client))->createParticipantCredentials($videoSession, $user, 'Some Member', false, true);
});

test('endRoom deletes the provider room by its stored provider_room_id', function () {
    $videoSession = VideoSession::factory()->create(['provider_room_id' => 'session-1-1']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('deleteRoom')->once()->with('session-1-1');

    (new DailyVideoProvider($client))->endRoom($videoSession);
});

test('endRoom is a no-op when the room was never actually created', function () {
    $videoSession = VideoSession::factory()->create(['provider_room_id' => null]);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldNotReceive('deleteRoom');

    (new DailyVideoProvider($client))->endRoom($videoSession);
});

// TT-3.2b/SCRUM-309: ejects the one participant, identified by our own known user id, without
// touching the rest of the room.
test('removeParticipant ejects only the given user from the provider room', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create(['provider_room_id' => 'session-1-1']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('ejectParticipants')->once()->with('session-1-1', [(string) $user->id]);

    (new DailyVideoProvider($client))->removeParticipant($videoSession, $user);
});

test('removeParticipant is a no-op when the room was never actually created', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create(['provider_room_id' => null]);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldNotReceive('ejectParticipants');

    (new DailyVideoProvider($client))->removeParticipant($videoSession, $user);
});

// TT-3.2f-e/SCRUM-322: changes an already-connected participant's publish capability live, keyed
// by our own known user id (same identification pattern as removeParticipant() above).

test('updateParticipantCapabilities sends canSend: true when both audio and video are allowed', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create(['provider_room_id' => 'session-1-1']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('updateRoomPermissions')
        ->once()
        ->with('session-1-1', [(string) $user->id => ['canSend' => true]]);

    (new DailyVideoProvider($client))->updateParticipantCapabilities($videoSession, $user, true, true);
});

test('updateParticipantCapabilities sends canSend: false when both are revoked', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create(['provider_room_id' => 'session-1-1']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('updateRoomPermissions')
        ->once()
        ->with('session-1-1', [(string) $user->id => ['canSend' => false]]);

    (new DailyVideoProvider($client))->updateParticipantCapabilities($videoSession, $user, false, false);
});

// TT-3.2f-g's own anonymity rule: an anonymous member granted speaking permission is audio-only.
test('updateParticipantCapabilities sends an explicit [audio] array for audio-only (anonymous speaker) permission', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create(['provider_room_id' => 'session-1-1']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('updateRoomPermissions')
        ->once()
        ->with('session-1-1', [(string) $user->id => ['canSend' => ['audio']]]);

    (new DailyVideoProvider($client))->updateParticipantCapabilities($videoSession, $user, true, false);
});

test('updateParticipantCapabilities sends an explicit [video] array for video-only permission', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create(['provider_room_id' => 'session-1-1']);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldReceive('updateRoomPermissions')
        ->once()
        ->with('session-1-1', [(string) $user->id => ['canSend' => ['video']]]);

    (new DailyVideoProvider($client))->updateParticipantCapabilities($videoSession, $user, false, true);
});

test('updateParticipantCapabilities is a no-op when the room was never actually created', function () {
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create(['provider_room_id' => null]);

    $client = Mockery::mock(DailyClient::class);
    $client->shouldNotReceive('updateRoomPermissions');

    (new DailyVideoProvider($client))->updateParticipantCapabilities($videoSession, $user, true, true);
});
