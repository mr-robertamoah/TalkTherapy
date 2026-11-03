<?php

use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Services\Daily\DailyVideoProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// SCRUM-334: the room and token properties actually sent over HTTP to Daily -- set explicitly, never
// left to the Daily domain's own defaults. Faked at the HTTP layer (not a mocked DailyClient), so
// this covers the real request bodies DailyClient builds.

function dvrdFakeDaily(): void
{
    Http::fake([
        '*/rooms' => fn (Request $request) => Http::response([
            'name' => $request->data()['name'],
            'url' => "https://talktherapy-test.daily.co/{$request->data()['name']}",
        ]),
        '*/meeting-tokens' => Http::response(['token' => 'jwt-token-value']),
    ]);
}

function dvrdSentTo(string $path): array
{
    $sent = Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), $path));

    expect($sent)->toHaveCount(1);

    return $sent[0][0]->data();
}

test('a new room turns screen sharing and knocking off explicitly, keeps chat off, and never enables recording', function () {
    dvrdFakeDaily();
    $session = Session::factory()->create(['end_time' => now()->addHour()]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider' => 'daily']);

    app(DailyVideoProvider::class)->createRoom($videoSession);

    $room = dvrdSentTo('/rooms');

    expect($room['privacy'])->toBe('private')
        ->and($room['properties']['enable_screenshare'])->toBeFalse()
        ->and($room['properties']['enable_knocking'])->toBeFalse()
        ->and($room['properties']['enable_chat'])->toBeFalse()
        // SCRUM-379: no phone calls out of a therapy room; SIP/phone dial-in only exist when configured.
        ->and($room['properties']['enable_dialout'])->toBeFalse()
        ->and($room['properties'])->not->toHaveKeys(['sip', 'dialout_config', 'streaming_endpoints'])
        // Daily has no "off" value for these -- recording is off only while nothing turns it on.
        ->and($room['properties'])->not->toHaveKeys(['enable_recording', 'enable_transcription_storage', 'permissions']);
});

test('a participant token turns screen sharing off and never enables recording', function () {
    dvrdFakeDaily();
    $user = User::factory()->create();
    $videoSession = VideoSession::factory()->create([
        'provider_room_id' => 'session-1-1',
        'provider_meta' => ['url' => 'https://talktherapy-test.daily.co/session-1-1'],
    ]);

    app(DailyVideoProvider::class)->createParticipantCredentials($videoSession, $user, 'Some Name', isOwner: true);

    $properties = dvrdSentTo('/meeting-tokens')['properties'];

    expect($properties['enable_screenshare'])->toBeFalse()
        // An owner can start a recording if the room or token allows one -- neither may.
        ->and($properties)->not->toHaveKeys(['enable_recording', 'start_cloud_recording', 'auto_start_transcription'])
        // SCRUM-357/334: the stable identity the call UI keys names on is the participant key.
        ->and($properties['user_id'])->toBe($videoSession->participantKeyFor($user->id));
});
