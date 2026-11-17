<?php

use App\Events\SpeakingPermissionChangedEvent;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Support\VideoParticipantKey;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// SCRUM-458: with video.provider 'fake', a join still goes through sessions.video.join and every
// one of JoinVideoSessionAction's checks -- only the adapter is swapped -- and the fake's
// credentials carry the send capability the server decided.

function fvjGroupCall(): array
{
    config(['video.provider' => 'fake']);

    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
        'anonymous' => false,
    ]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => false]);

    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    return compact('session', 'counsellorUser', 'member');
}

test('a member who may only watch gets fake credentials that let them send nothing', function () {
    ['session' => $session, 'member' => $member] = fvjGroupCall();

    $this->actingAs($member)->postJson(route('sessions.video.join', $session->id))
        ->assertOk()
        ->assertJsonPath('sessionId', $session->id)
        ->assertJsonPath('participantKey', VideoParticipantKey::for($session->id, $member->id))
        ->assertJsonPath('canSend', ['audio' => false, 'video' => false])
        ->assertJsonPath('participant.receiveOnly', true)
        ->assertJsonPath('participant.canRaiseHand', true);

    $videoSession = VideoSession::query()->where('session_id', $session->id)->sole();
    expect($videoSession->provider)->toBe('fake')
        ->and($videoSession->provider_room_id)->toBe("fake-session-{$session->id}-{$videoSession->id}");
});

test('a counsellor joining the same fake call may send audio and video', function () {
    ['session' => $session, 'counsellorUser' => $counsellorUser] = fvjGroupCall();

    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', $session->id))
        ->assertOk()
        ->assertJsonPath('canSend', ['audio' => true, 'video' => true])
        ->assertJsonPath('participant.canModerate', true);
});

test('a counsellor grants a joined member speaking on the fake, and the member\'s client is told', function () {
    ['session' => $session, 'counsellorUser' => $counsellorUser, 'member' => $member] = fvjGroupCall();
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', $session->id))->assertOk();
    $this->actingAs($member)->postJson(route('sessions.video.join', $session->id))->assertOk();
    Event::fake([SpeakingPermissionChangedEvent::class]);
    Log::spy();

    $key = VideoParticipantKey::for($session->id, $member->id);
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.participants.grant_speaking', [
        'sessionId' => $session->id,
        'participantKey' => $key,
    ]))->assertSuccessful();

    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn (SpeakingPermissionChangedEvent $event) => $event->broadcastWith()['participantKey'] === $key
        && $event->broadcastWith()['canSendAudio'] === true);
    Log::shouldNotHaveReceived('warning');
});

test('ending a fake call ends it locally without logging a provider failure', function () {
    ['session' => $session, 'counsellorUser' => $counsellorUser] = fvjGroupCall();
    $this->actingAs($counsellorUser)->postJson(route('sessions.video.join', $session->id))->assertOk();
    Log::spy();

    $this->actingAs($counsellorUser)->postJson(route('sessions.video.end', $session->id))->assertSuccessful();

    expect(VideoSession::query()->where('session_id', $session->id)->sole()->ended_at)->not->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

test('ending a call a real provider opened, after switching to the fake, takes the logged provider-failure path', function () {
    ['session' => $session, 'counsellorUser' => $counsellorUser] = fvjGroupCall();
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider' => 'daily']);
    Log::spy();

    $this->actingAs($counsellorUser)->postJson(route('sessions.video.end', $session->id))->assertSuccessful();

    expect($videoSession->fresh()->ended_at)->not->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'teardown failed'));
});

test('the fake changes none of the join checks: an outsider is still refused', function () {
    ['session' => $session] = fvjGroupCall();

    $this->actingAs(User::factory()->adult()->create())
        ->postJson(route('sessions.video.join', $session->id))
        ->assertStatus(422);

    expect(VideoSession::query()->where('session_id', $session->id)->exists())->toBeFalse();
});
