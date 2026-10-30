<?php

use App\Actions\Video\LowerHandAction;
use App\Events\HandLoweredEvent;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\Event;

// TT-3.2f-f/SCRUM-323: self-service withdrawal of your own raised hand.

function groupVideoCallForLowerHandAction(): array
{
    $member = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
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

    return compact('member', 'session', 'videoSession', 'memberParticipant');
}

test('a member can lower their own raised hand', function () {
    Event::fake();
    $data = groupVideoCallForLowerHandAction();
    $raise = $data['memberParticipant']->handRaises()->create(['raised_at' => now()]);

    LowerHandAction::new()->execute($data['session'], $data['member']);

    expect($raise->fresh()->lowered_at)->not->toBeNull();
    Event::assertDispatched(HandLoweredEvent::class, fn ($event) => $event->broadcastWith() === [
        'sessionId' => $data['session']->id,
        'userId' => $data['member']->id,
    ]);
});

test('lowering when no hand is raised is a safe no-op with no broadcast', function () {
    Event::fake();
    $data = groupVideoCallForLowerHandAction();

    LowerHandAction::new()->execute($data['session'], $data['member']);

    Event::assertNotDispatched(HandLoweredEvent::class);
});

test('lowering only touches the acting member\'s own raise, never anyone else\'s', function () {
    Event::fake();
    $data = groupVideoCallForLowerHandAction();
    $otherParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => User::factory()->create()->id,
        'joined_at' => now(),
        'left_at' => null,
    ]);
    $otherRaise = $otherParticipant->handRaises()->create(['raised_at' => now()]);

    LowerHandAction::new()->execute($data['session'], $data['member']);

    expect($otherRaise->fresh()->lowered_at)->toBeNull();
    Event::assertNotDispatched(HandLoweredEvent::class);
});

test('lowering with no open video session is a safe no-op', function () {
    Event::fake();
    $data = groupVideoCallForLowerHandAction();
    $data['videoSession']->update(['ended_at' => now()]);

    LowerHandAction::new()->execute($data['session'], $data['member']);

    Event::assertNotDispatched(HandLoweredEvent::class);
});
