<?php

use App\Actions\Video\JoinVideoSessionAction;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
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
    // values(): the filter keeps each request's original position, and a join also creates a room.
    $sent = Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), $path))->values();

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

    app(DailyVideoProvider::class)->createParticipantCredentials($videoSession, $user, 'Some Name');

    $properties = dvrdSentTo('/meeting-tokens')['properties'];

    expect($properties['enable_screenshare'])->toBeFalse()
        // Recording starts only if the room or token allows one -- neither may.
        ->and($properties)->not->toHaveKeys(['enable_recording', 'start_cloud_recording', 'auto_start_transcription'])
        // SCRUM-357/334: the stable identity the call UI keys names on is the participant key.
        ->and($properties['user_id'])->toBe($videoSession->participantKeyFor($user->id));
});

// SCRUM-380: the 1:1 counsellor was the one Daily owner left. Joined through the real action and
// provider, their token is now a plain participant's: no owner rights, explicit sends, no admin.
test('a 1:1 counsellor joining a call gets a non-owner token with explicit permissions', function () {
    dvrdFakeDaily();
    config(['video.provider' => 'daily']);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory()->adult()->create()->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
        'end_time' => now()->addHour(),
    ]);

    JoinVideoSessionAction::new()->execute($session, $counsellor->user);

    $properties = dvrdSentTo('/meeting-tokens')['properties'];

    expect($properties['is_owner'])->toBeFalse()
        ->and($properties['permissions'])->toBe(['canSend' => ['audio', 'video'], 'canAdmin' => false]);
});
