<?php

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
                && $properties['is_owner'] === true;
        }))
        ->andReturn(['token' => 'jwt-token-value']);

    $result = (new DailyVideoProvider($client))->createParticipantCredentials($videoSession, $user, 'Anonymous Display Name', true);

    expect($result)->toBe(['url' => 'https://example.daily.co/room', 'token' => 'jwt-token-value']);
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
