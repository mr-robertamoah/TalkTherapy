<?php

use App\Actions\Video\RaiseHandAction;
use App\Events\HandRaisedEvent;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;
use Illuminate\Support\Facades\Event;

// TT-3.2f-f/SCRUM-323: a receive-only member, already admitted to the call, asks to speak.

function groupVideoCallForRaiseHandAction(): array
{
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
    ]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);
    $memberParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $member->id,
        'joined_at' => now(),
        'left_at' => null,
    ]);

    return compact('creator', 'groupTherapy', 'counsellorUser', 'member', 'session', 'videoSession', 'memberParticipant');
}

test('an admitted receive-only member can raise their hand', function () {
    Event::fake();
    $data = groupVideoCallForRaiseHandAction();

    RaiseHandAction::new()->execute($data['session'], $data['member']);

    expect($data['memberParticipant']->currentHandRaise())->not->toBeNull();
    Event::assertDispatched(HandRaisedEvent::class, fn ($event) => $event->broadcastWith() === [
        'sessionId' => $data['session']->id,
        'userId' => $data['member']->id,
    ]);
});

test('raising an already-raised hand is idempotent -- one open row, one broadcast', function () {
    Event::fake();
    $data = groupVideoCallForRaiseHandAction();

    RaiseHandAction::new()->execute($data['session'], $data['member']);
    RaiseHandAction::new()->execute($data['session'], $data['member']);

    expect($data['memberParticipant']->handRaises()->whereNull('lowered_at')->count())->toBe(1);
    Event::assertDispatchedTimes(HandRaisedEvent::class, 1);
});

test('a member must already be in the call to raise their hand', function () {
    $data = groupVideoCallForRaiseHandAction();
    $data['memberParticipant']->update(['left_at' => now()]);

    expect(fn () => RaiseHandAction::new()->execute($data['session'], $data['member']))
        ->toThrow(VideoException::class, 'Join the video call before raising your hand.');
});

test('a member cannot raise their hand when there is no open video session', function () {
    $data = groupVideoCallForRaiseHandAction();
    $data['videoSession']->update(['ended_at' => now()]);

    expect(fn () => RaiseHandAction::new()->execute($data['session'], $data['member']))
        ->toThrow(VideoException::class, 'Join the video call before raising your hand.');
});

test('a member who already holds speaking permission cannot raise their hand', function () {
    $data = groupVideoCallForRaiseHandAction();
    VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $data['memberParticipant']->id,
        'revoked_at' => null,
    ]);

    expect(fn () => RaiseHandAction::new()->execute($data['session'], $data['member']))
        ->toThrow(VideoException::class, 'You already have permission to speak.');
});

test('counsellors and the creator cannot raise a hand -- they can already speak', function () {
    $data = groupVideoCallForRaiseHandAction();

    expect(fn () => RaiseHandAction::new()->execute($data['session'], $data['counsellorUser']))
        ->toThrow(VideoException::class, 'You can already speak in this video call.');
    expect(fn () => RaiseHandAction::new()->execute($data['session'], $data['creator']))
        ->toThrow(VideoException::class, 'You can already speak in this video call.');
});

test('raising a hand is not available for a 1:1 Therapy video session', function () {
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect(fn () => RaiseHandAction::new()->execute($session, $client))
        ->toThrow(VideoException::class, 'Raising a hand is only available in group therapy video calls.');
});

// TT-3.2f-g/SCRUM-324: a minor can never be granted yet, so their hand-raise is refused up front
// rather than left as a dead-end entry in the counsellors' queue.
test('a minor member cannot raise their hand', function () {
    Event::fake();
    $data = groupVideoCallForRaiseHandAction();
    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['was_minor_at_join' => true]);

    expect(fn () => RaiseHandAction::new()->execute($data['session'], $data['member']))
        ->toThrow(VideoException::class, 'Speaking permission is not yet available to a minor member in group therapy video.');

    expect($data['memberParticipant']->currentHandRaise())->toBeNull();
    Event::assertNotDispatched(HandRaisedEvent::class);
});
