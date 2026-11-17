<?php

use App\Actions\Video\EjectVideoParticipantAction;
use App\Actions\Video\EndVideoSessionAction;
use App\Actions\Video\RemoveParticipantFromVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Events\VideoParticipantRemovedEvent;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

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

    $rows = VideoSessionParticipant::query()->where('video_session_id', $videoSessionId)->where('participant_id', $data['client']->id)->orderBy('id')->get();
    // The row the drop left open is closed by the rejoin (left, not removed); the new one is live.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->left_at)->not->toBeNull()
        ->and($rows[0]->removed_at)->toBeNull()
        ->and($rows[1]->left_at)->toBeNull();
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

    $this->travel(1)->seconds();
    vrgJoin($this, $data['client'], $data['session'])->assertOk()->assertJsonPath('videoSessionId', $videoSessionId);

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertOk();
});

test('a removal is not hidden by a later row that an earlier rejoin opened and closed again', function () {
    $data = vrgSession();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId'); // row 1, left open by a drop
    $videoSession = VideoSession::find($videoSessionId);
    $row1 = $videoSession->activeParticipantFor($data['client']->id);

    // An earlier rejoin's row: opened, its provider join failed, then left -- later than row 1.
    $this->travel(1)->seconds();
    VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSessionId, 'participant_id' => $data['client']->id,
        'joined_at' => now(), 'left_at' => now(),
    ]);

    // The counsellor's eject stamps only the row still open: row 1.
    $this->travel(1)->seconds();
    EjectVideoParticipantAction::new()->execute($videoSession, $row1);
    expect($row1->fresh()->removed_at)->not->toBeNull();

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])
        ->assertStatus(422)
        ->assertJsonPath('rejoinRefused', 'removed');

    // Their own join is still allowed, and a drop after it may rejoin again.
    $this->travel(1)->seconds();
    vrgJoin($this, $data['client'], $data['session'])->assertOk();
    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertOk();
});

test('a join in the same second as the removal does not count as joining since', function () {
    $data = vrgSession();
    $this->freezeSecond();
    $videoSessionId = vrgJoin($this, $data['client'], $data['session'])->json('videoSessionId');
    $videoSession = VideoSession::find($videoSessionId);
    EjectVideoParticipantAction::new()->execute($videoSession, $videoSession->activeParticipantFor($data['client']->id));
    VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSessionId, 'participant_id' => $data['client']->id,
        'joined_at' => now(), 'left_at' => now(),
    ]);

    vrgJoin($this, $data['client'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])
        ->assertStatus(422)
        ->assertJsonPath('rejoinRefused', 'removed');
});

test('a counsellor\'s removal waits for the person\'s join lock, and says so when it is busy', function () {
    config(['video.join_lock_wait_seconds' => 0]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'public' => true,
    ]);
    $counsellorUser = User::factory()->create();
    $groupTherapy->counsellors()->attach(Counsellor::factory()->create(['user_id' => $counsellorUser->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-vrg']);
    $row = VideoSessionParticipant::factory()->create(['video_session_id' => $videoSession->id, 'participant_id' => $member->id, 'left_at' => null]);

    $joining = Cache::store(config('cache.scheduler_store'))->lock("video-join:{$session->id}:{$member->id}", 30);
    $joining->get();

    expect(fn () => RemoveParticipantFromVideoSessionAction::new()->execute($session, $counsellorUser, $member->id))
        ->toThrow(VideoException::class);
    expect($row->fresh()->left_at)->toBeNull();

    $joining->release();
    RemoveParticipantFromVideoSessionAction::new()->execute($session, $counsellorUser, $member->id);
    expect($row->fresh()->removed_at)->not->toBeNull();
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

function vrgGroupSession(): array
{
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id,
        'public' => true, 'anonymous' => false,
    ]);
    $counsellorUser = User::factory()->create();
    $groupTherapy->counsellors()->attach(Counsellor::factory()->create(['user_id' => $counsellorUser->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class,
        'type' => 'ONLINE', 'status' => 'IN_SESSION', 'start_time' => now(),
    ]);

    return compact('counsellorUser', 'member', 'session');
}

test('a removal between automatic rejoin attempts (no open row) still refuses the next rejoin', function () {
    Event::fake([VideoParticipantRemovedEvent::class]);
    $data = vrgGroupSession();
    $videoSessionId = vrgJoin($this, $data['member'], $data['session'])->json('videoSessionId'); // R1, left open by a drop

    // A rejoin attempt closes R1 and opens R2; its provider join fails, and the awaited leave closes R2.
    $this->travel(1)->seconds();
    vrgJoin($this, $data['member'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])->assertOk();
    $this->actingAs($data['member'])->postJson(route('sessions.video.leave', ['sessionId' => $data['session']->id]))->assertOk();
    $videoSession = VideoSession::find($videoSessionId);
    expect($videoSession->activeParticipantFor($data['member']->id))->toBeNull();

    // The counsellor removes them in the backoff gap.
    $this->travel(1)->seconds();
    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    $latest = $videoSession->participantRowsFor($data['member']->id)->orderByDesc('id')->first();
    expect($latest->removed_at)->not->toBeNull();
    Event::assertDispatched(VideoParticipantRemovedEvent::class);

    vrgJoin($this, $data['member'], $data['session'], ['rejoin' => true, 'videoSessionId' => $videoSessionId])
        ->assertStatus(422)
        ->assertJsonPath('rejoinRefused', 'removed');

    // Their own join is still allowed.
    $this->travel(1)->seconds();
    vrgJoin($this, $data['member'], $data['session'])->assertOk();
});

test('removing someone with no row in the call stays a no-op', function () {
    Event::fake([VideoParticipantRemovedEvent::class]);
    $data = vrgGroupSession();
    $other = User::factory()->adult()->create();
    VideoSession::factory()->create(['session_id' => $data['session']->id, 'provider_room_id' => 'room-vrg-2']);

    expect(RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $other->id))->toBeTrue();
    Event::assertNotDispatched(VideoParticipantRemovedEvent::class);
});
