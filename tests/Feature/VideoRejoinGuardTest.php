<?php

use App\Actions\Video\EjectVideoParticipantAction;
use App\Actions\Video\EndVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;

// TT-3.3b/SCRUM-459 (security review HIGH-1): a browser's AUTOMATIC rejoin after its connection
// dropped names the call it was in; the server refuses it when that call has ended or this person
// was taken out of it meanwhile -- broadcasts a browser missed while offline must not be undone by
// its reconnect. A person's own "join video" is unaffected. Video never touches Session.status.

function vrgProvider(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => "vrg-room-{$videoSession->id}", 'meta' => []];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return ['token' => "vrg-token-{$user->id}"];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

function vrgSession(): array
{
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
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

function vrgJoin($test, User $user, Session $session, array $body = [])
{
    return $test->actingAs($user)->postJson(route('sessions.video.join', ['sessionId' => $session->id]), $body);
}

beforeEach(function () {
    app()->instance(VideoProviderInterface::class, vrgProvider());
});

test('a join response names the video session it joined', function () {
    $data = vrgSession();

    $response = vrgJoin($this, $data['client'], $data['session'])->assertOk();

    expect($response->json('videoSessionId'))->toBe(VideoSession::query()->where('session_id', $data['session']->id)->value('id'));
});

test('an automatic rejoin into a live call, by someone not taken out of it, succeeds', function () {
    $data = vrgSession();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])
        ->assertOk()
        ->assertJsonPath('videoSessionId', $videoSessionId);

    expect(VideoSessionParticipant::query()->where('video_session_id', $videoSessionId)->where('participant_id', $data['client']->id)->count())->toBe(2);
});

test('an automatic rejoin into a call that has ended is refused and creates nothing', function () {
    $data = vrgSession();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');
    EndVideoSessionAction::new()->execute($data['session'], $data['counsellorUser']);
    $rows = VideoSessionParticipant::query()->count();

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])
        ->assertStatus(422)
        ->assertJsonPath('rejoinRefused', 'ended');

    expect(VideoSession::query()->where('session_id', $data['session']->id)->count())->toBe(1)
        ->and(VideoSessionParticipant::query()->count())->toBe($rows);
});

test('an automatic rejoin naming a different video session is refused as ended', function () {
    $data = vrgSession();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId + 1000])
        ->assertStatus(422)
        ->assertJsonPath('rejoinRefused', 'ended');
});

test('an automatic rejoin after being taken out of the call is refused', function () {
    $data = vrgSession();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');
    $videoSession = VideoSession::find($videoSessionId);
    EjectVideoParticipantAction::new()->execute($videoSession, $videoSession->activeParticipantFor($data['client']->id));

    expect($videoSession->participantRowsFor($data['client']->id)->first()->removed_at)->not->toBeNull();

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])
        ->assertStatus(422)
        ->assertJsonPath('rejoinRefused', 'removed');

    expect($videoSession->activeParticipantFor($data['client']->id))->toBeNull();
});

test('a person\'s own join after being taken out is still allowed, and a later drop can rejoin', function () {
    $data = vrgSession();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');
    $videoSession = VideoSession::find($videoSessionId);
    EjectVideoParticipantAction::new()->execute($videoSession, $videoSession->activeParticipantFor($data['client']->id));

    vrgJoin($this, $data['client'], $data['session'])->assertOk()->assertJsonPath('videoSessionId', $videoSessionId);

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertOk();
});

test('a leave is not a removal -- an automatic rejoin after one is allowed', function () {
    $data = vrgSession();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');
    $this->actingAs($data['client'])->postJson(route('sessions.video.leave', ['sessionId' => $data['session']->id]))->assertOk();

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertOk();
});

test('a rejoin must name its video session', function () {
    $data = vrgSession();
    vrgJoin($this, $data['client'], $data['session'])->assertOk();

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('videoSessionId');

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('videoSessionId');
});

test('refused and allowed rejoins never touch Session.status', function () {
    $data = vrgSession();
    $original = $data['session']->status;

    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');
    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertOk();
    expect($data['session']->fresh()->status)->toBe($original);

    $videoSession = VideoSession::find($videoSessionId);
    EjectVideoParticipantAction::new()->execute($videoSession, $videoSession->activeParticipantFor($data['client']->id));
    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertStatus(422);
    expect($data['session']->fresh()->status)->toBe($original);

    EndVideoSessionAction::new()->execute($data['session'], $data['counsellorUser']);
    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertStatus(422);
    expect($data['session']->fresh()->status)->toBe($original);
});
