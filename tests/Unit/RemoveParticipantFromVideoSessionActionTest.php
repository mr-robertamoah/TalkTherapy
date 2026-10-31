<?php

use App\Actions\Video\RemoveParticipantFromVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Events\HandLoweredEvent;
use App\Events\SpeakingPermissionChangedEvent;
use App\Events\VideoParticipantRemovedEvent;
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
use Illuminate\Support\Facades\Log;

// TT-3.2b/SCRUM-309: ejects one specific participant without ending the room for anyone else --
// GroupTherapy-only, counsellor-only (mirrors TT-3.2d's own EndVideoSessionAction restriction).

function onlineGroupTherapySessionForRemoveParticipantAction(): array
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
    $otherCounsellorUser = User::factory()->create();
    $otherCounsellor = Counsellor::factory()->create(['user_id' => $otherCounsellorUser->id]);
    $groupTherapy->counsellors()->attach($otherCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);

    return compact('creator', 'groupTherapy', 'counsellorUser', 'otherCounsellorUser', 'member', 'session', 'videoSession');
}

function fakeVideoProviderRecordingRemovals(array &$removedUserIds, bool $shouldThrow = false)
{
    return new class($removedUserIds, $shouldThrow) implements VideoProviderInterface
    {
        public function __construct(private array &$removedUserIds, private bool $shouldThrow) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            if ($this->shouldThrow) {
                throw new RuntimeException('Daily API is unreachable.');
            }

            $this->removedUserIds[] = $user->id;
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

test('an active counsellor can remove an ordinary member from the group video call', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $participant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['member']->id,
        'left_at' => null,
    ]);
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($participant->fresh()->left_at)->not->toBeNull()
        ->and($removedUserIds)->toBe([$data['member']->id]);
    Event::assertDispatched(VideoParticipantRemovedEvent::class);
});

test('a counsellor can remove another counsellor from the group video call', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $participant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['otherCounsellorUser']->id,
        'left_at' => null,
    ]);
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['otherCounsellorUser']->id);

    expect($participant->fresh()->left_at)->not->toBeNull();
});

test('an ordinary member cannot remove anyone from the group video call', function () {
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $participant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['counsellorUser']->id,
        'left_at' => null,
    ]);
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    expect(fn () => RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['member'], $data['counsellorUser']->id))
        ->toThrow(VideoException::class, 'Only a counsellor may remove a participant from this group video call.');

    expect($participant->fresh()->left_at)->toBeNull()
        ->and($removedUserIds)->toBe([]);
});

test('the group\'s own creator (a client, not a counsellor) cannot remove anyone', function () {
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    expect(fn () => RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['creator'], $data['member']->id))
        ->toThrow(VideoException::class);
});

test('a counsellor cannot remove themselves this way -- must use leave instead', function () {
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    expect(fn () => RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['counsellorUser']->id))
        ->toThrow(VideoException::class, 'Use leave, not remove, to exit the video call yourself.');
});

test('removal is not available for a 1:1 Therapy video session', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    expect(fn () => RemoveParticipantFromVideoSessionAction::new()->execute($session, $counsellorUser, $client->id))
        ->toThrow(VideoException::class, 'Removing a specific participant is only available for group therapy video calls.');
});

test('removing a target who is not currently an active participant is a safe no-op', function () {
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($removedUserIds)->toBe([]);
});

// TT-3.2b/SCRUM-309 security-review finding: a target id that doesn't exist as a User at all must
// behave IDENTICALLY (a safe no-op, no exception) to a target id that exists but simply isn't a
// live participant of this call -- the action must never look the id up against the global
// `users` table itself, or a counsellor could use a distinguishable response to enumerate which
// user ids exist platform-wide.
test('removing a target id that does not exist as a User at all is the same safe no-op as removing a non-participant', function () {
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));
    $nonexistentUserId = User::max('id') + 1000;

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $nonexistentUserId);

    expect($removedUserIds)->toBe([]);
});

test('when there is no open video session at all, removal is a safe no-op', function () {
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $data['videoSession']->update(['ended_at' => now()]);
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($removedUserIds)->toBe([]);
});

test('a provider-side removal failure still marks the participant left locally, matching removeParticipant()\'s best-effort contract', function () {
    Event::fake();
    Log::shouldReceive('warning')->once();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $participant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['member']->id,
        'left_at' => null,
    ]);
    $removedUserIds = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovals($removedUserIds, true));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($participant->fresh()->left_at)->not->toBeNull();
    Event::assertDispatched(VideoParticipantRemovedEvent::class);
});

// TT-3.2f-i/SCRUM-326: removal also clears the removed member's speaking state, through the SAME
// shared revoke a counsellor's manual revoke uses (reason: participant_removed), before ejecting.

function fakeVideoProviderRecordingRemovalOrder(array &$calls, bool $capabilityUpdateThrows = false)
{
    return new class($calls, $capabilityUpdateThrows) implements VideoProviderInterface
    {
        public function __construct(private array &$calls, private bool $capabilityUpdateThrows) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            $this->calls[] = ['remove', $user->id];
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void
        {
            if ($this->capabilityUpdateThrows) {
                throw new RuntimeException('Daily API is unreachable.');
            }

            $this->calls[] = ['capabilities', $user->id, $canSendAudio, $canSendVideo];
        }
    };
}

function liveMemberParticipantForRemovalCleanup(array $data): VideoSessionParticipant
{
    return VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['member']->id,
        'joined_at' => now(),
        'left_at' => null,
    ]);
}

test('removing a member who holds speaking permission revokes it (participant_removed) and mutes them before ejecting', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $participant = liveMemberParticipantForRemovalCleanup($data);
    $grant = VideoSessionSpeakingGrant::factory()->create(['video_session_participant_id' => $participant->id, 'revoked_at' => null]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovalOrder($calls));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    $grant = $grant->fresh();
    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::participant_removed->value)
        ->and($grant->revoked_by_user_id)->toBe($data['counsellorUser']->id)
        ->and($calls)->toBe([
            ['capabilities', $data['member']->id, false, false],
            ['remove', $data['member']->id],
        ]);
    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => $event->broadcastWith() === [
        'sessionId' => $data['session']->id,
        'userId' => $data['member']->id,
        'canSendAudio' => false,
        'canSendVideo' => false,
        'reason' => SpeakingPermissionRevocationReasonEnum::participant_removed->value,
    ]);
    Event::assertDispatched(VideoParticipantRemovedEvent::class);
});

test('removing a member with a raised hand lowers it and broadcasts that', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $participant = liveMemberParticipantForRemovalCleanup($data);
    $raise = $participant->handRaises()->create(['raised_at' => now()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovalOrder($calls));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($raise->fresh()->lowered_at)->not->toBeNull();
    Event::assertDispatched(HandLoweredEvent::class, fn ($event) => $event->broadcastWith() === [
        'sessionId' => $data['session']->id,
        'userId' => $data['member']->id,
    ]);
});

test('removing a member with no speaking state touches nothing extra and broadcasts no extra events', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    liveMemberParticipantForRemovalCleanup($data);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovalOrder($calls));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($calls)->toBe([['remove', $data['member']->id]]);
    Event::assertNotDispatched(HandLoweredEvent::class);
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

test('a failed provider mute during removal still closes the grant and still ejects', function () {
    Event::fake();
    Log::shouldReceive('warning')->once();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $participant = liveMemberParticipantForRemovalCleanup($data);
    $grant = VideoSessionSpeakingGrant::factory()->create(['video_session_participant_id' => $participant->id, 'revoked_at' => null]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovalOrder($calls, true));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($grant->fresh()->isActive())->toBeFalse()
        ->and($participant->fresh()->left_at)->not->toBeNull()
        ->and($calls)->toBe([['remove', $data['member']->id]]);
});

// A reconnect can leave an older join row still open (SCRUM-330) -- its grant and hand-raise must be
// cleared too, not just the live row's.
test('removal also clears speaking state left on a stale, still-open join row', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $staleParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['member']->id,
        'joined_at' => now()->subMinutes(5),
        'left_at' => null,
    ]);
    $staleGrant = VideoSessionSpeakingGrant::factory()->create(['video_session_participant_id' => $staleParticipant->id, 'revoked_at' => null]);
    $staleRaise = $staleParticipant->handRaises()->create(['raised_at' => now()->subMinutes(4)]);
    liveMemberParticipantForRemovalCleanup($data);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovalOrder($calls));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    // Every open row is closed too, so the removed user is no longer "in the call" anywhere.
    expect($staleGrant->fresh()->isActive())->toBeFalse()
        ->and($staleRaise->fresh()->lowered_at)->not->toBeNull()
        ->and($staleParticipant->fresh()->left_at)->not->toBeNull()
        ->and($data['videoSession']->activeParticipantFor($data['member']->id))->toBeNull();
});

test('removal never touches another participant\'s grant or hand-raise', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    liveMemberParticipantForRemovalCleanup($data);
    $otherParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => User::factory()->create()->id,
        'joined_at' => now(),
        'left_at' => null,
    ]);
    $otherGrant = VideoSessionSpeakingGrant::factory()->create(['video_session_participant_id' => $otherParticipant->id, 'revoked_at' => null]);
    $otherRaise = $otherParticipant->handRaises()->create(['raised_at' => now()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovalOrder($calls));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($otherGrant->fresh()->isActive())->toBeTrue()
        ->and($otherRaise->fresh()->lowered_at)->toBeNull();
});

// Security-review finding: when only a STALE row held the grant, removal still mutes the person at the
// provider before ejecting -- a reconnected Chime attendee may otherwise keep that grant's capability.
test('a grant held only on a stale row still gets a provider mute before the eject', function () {
    Event::fake();
    $data = onlineGroupTherapySessionForRemoveParticipantAction();
    $staleParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['member']->id,
        'joined_at' => now()->subMinutes(5),
        'left_at' => null,
    ]);
    VideoSessionSpeakingGrant::factory()->create(['video_session_participant_id' => $staleParticipant->id, 'revoked_at' => null]);
    liveMemberParticipantForRemovalCleanup($data);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRemovalOrder($calls));

    RemoveParticipantFromVideoSessionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($calls)->toBe([
        ['capabilities', $data['member']->id, false, false],
        ['remove', $data['member']->id],
    ]);
});
