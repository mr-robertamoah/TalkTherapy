<?php

use App\Actions\GroupTherapy\EnsureGroupTherapyCanBecomeAnonymousAction;
use App\DTOs\GroupTherapyDTO;
use App\Exceptions\TherapyException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Services\GroupTherapyService;

// SCRUM-332: a group can't be switched to anonymous while one of its video calls is live -- the
// people in that call have already seen members' names (and speakers' faces).

function groupWithSessionForAnonymityGuard(bool $anonymous): array
{
    $groupTherapy = GroupTherapy::factory()->create(['anonymous' => $anonymous]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);

    return compact('groupTherapy', 'session');
}

// An open epoch with someone still in it -- the only kind of call that counts as "live".
function liveCallForAnonymityGuard(Session $session, array $overrides = []): VideoSession
{
    $videoSession = VideoSession::factory()->create(array_merge(['session_id' => $session->id, 'ended_at' => null], $overrides));
    VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => User::factory()->create()->id,
        'joined_at' => now(),
        'left_at' => null,
    ]);

    return $videoSession;
}

test('making a group anonymous during a live video call is refused', function () {
    $data = groupWithSessionForAnonymityGuard(false);
    liveCallForAnonymityGuard($data['session']);

    expect(fn () => EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($data['groupTherapy'], true))
        ->toThrow(TherapyException::class, 'End the current video call before making this group anonymous.');
});

test('making a group anonymous with no live call is allowed', function () {
    $data = groupWithSessionForAnonymityGuard(false);
    VideoSession::factory()->create(['session_id' => $data['session']->id, 'ended_at' => now()->subHour()]);

    EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($data['groupTherapy'], true);
})->throwsNoExceptions();

test('turning anonymity off, keeping it on, or not touching it is always allowed mid-call', function () {
    $anonymousGroup = groupWithSessionForAnonymityGuard(true);
    liveCallForAnonymityGuard($anonymousGroup['session']);
    $openGroup = groupWithSessionForAnonymityGuard(false);
    liveCallForAnonymityGuard($openGroup['session']);

    EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($anonymousGroup['groupTherapy'], false);
    EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($anonymousGroup['groupTherapy'], true);
    EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($openGroup['groupTherapy'], null);
    EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($openGroup['groupTherapy'], false);
})->throwsNoExceptions();

test('another group\'s live call does not block this group', function () {
    $data = groupWithSessionForAnonymityGuard(false);
    $other = groupWithSessionForAnonymityGuard(false);
    liveCallForAnonymityGuard($other['session']);

    EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($data['groupTherapy'], true);
})->throwsNoExceptions();

// Wiring: the guard actually runs on the real update path, and blocks the write.
test('GroupTherapyService::updateGroupTherapy refuses to make a group anonymous mid-call and leaves it unchanged', function () {
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'anonymous' => false,
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
    liveCallForAnonymityGuard($session);

    expect(fn () => GroupTherapyService::new()->updateGroupTherapy(GroupTherapyDTO::new()->fromArray([
        'user' => $creator,
        'groupTherapy' => $groupTherapy,
        'anonymous' => true,
    ])))->toThrow(TherapyException::class, 'End the current video call before making this group anonymous.');

    expect((bool) $groupTherapy->fresh()->anonymous)->toBeFalse();
});

// Reviewer finding: an epoch everyone left stays open (only an explicit End closes it), and the
// creator can't end group calls -- it must not block making the group anonymous forever.
test('an open call that everyone has left does not block making the group anonymous', function () {
    $data = groupWithSessionForAnonymityGuard(false);
    $videoSession = VideoSession::factory()->create(['session_id' => $data['session']->id, 'ended_at' => null]);
    VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => User::factory()->create()->id,
        'joined_at' => now()->subHour(),
        'left_at' => now()->subMinutes(30),
    ]);

    EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($data['groupTherapy'], true);
})->throwsNoExceptions();
