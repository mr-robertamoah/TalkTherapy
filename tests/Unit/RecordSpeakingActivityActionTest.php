<?php

use App\Actions\Video\RecordSpeakingActivityAction;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;

// TT-3.2f-h/SCRUM-325: a granted member's own activity ping, keeping the idle sweep off their grant.

function groupCallWithGrantForSpeakingActivity(): array
{
    $member = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);
    $participant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $member->id,
        'joined_at' => now()->subMinutes(10),
        'left_at' => null,
    ]);
    $grant = VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $participant->id,
        'last_activity_at' => now()->subMinutes(4),
        'revoked_at' => null,
    ]);

    return compact('member', 'session', 'videoSession', 'participant', 'grant');
}

test('a member\'s ping refreshes their own open grant\'s last activity', function () {
    $this->freezeTime();
    $data = groupCallWithGrantForSpeakingActivity();

    RecordSpeakingActivityAction::new()->execute($data['session'], $data['member']);

    expect($data['grant']->fresh()->last_activity_at->timestamp)->toBe(now()->timestamp);
});

test('a ping from someone else in the call never touches the member\'s grant', function () {
    $data = groupCallWithGrantForSpeakingActivity();
    $other = User::factory()->create();
    VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $other->id,
        'joined_at' => now(),
        'left_at' => null,
    ]);
    $before = $data['grant']->last_activity_at;

    RecordSpeakingActivityAction::new()->execute($data['session'], $other);

    expect($data['grant']->fresh()->last_activity_at->equalTo($before))->toBeTrue();
});

test('a ping cannot revive a revoked grant', function () {
    $data = groupCallWithGrantForSpeakingActivity();
    $data['grant']->update(['revoked_at' => now(), 'revocation_reason' => 'MANUAL']);
    $before = $data['grant']->fresh()->last_activity_at;

    RecordSpeakingActivityAction::new()->execute($data['session'], $data['member']);

    $grant = $data['grant']->fresh();
    expect($grant->isActive())->toBeFalse()
        ->and($grant->last_activity_at->equalTo($before))->toBeTrue();
});

test('a ping with no open video call, or from a member who has left, is a silent no-op', function () {
    $data = groupCallWithGrantForSpeakingActivity();
    $data['participant']->update(['left_at' => now()]);
    $before = $data['grant']->last_activity_at;

    RecordSpeakingActivityAction::new()->execute($data['session'], $data['member']);
    $data['videoSession']->update(['ended_at' => now()]);
    RecordSpeakingActivityAction::new()->execute($data['session'], $data['member']);

    expect($data['grant']->fresh()->last_activity_at->equalTo($before))->toBeTrue();
});
