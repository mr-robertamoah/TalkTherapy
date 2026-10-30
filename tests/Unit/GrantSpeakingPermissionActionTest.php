<?php

use App\Actions\Video\GrantSpeakingPermissionAction;
use App\Contracts\VideoProviderInterface;
use App\Events\HandLoweredEvent;
use App\Events\SpeakingPermissionChangedEvent;
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

// TT-3.2f-f/SCRUM-323: any active counsellor grants a receive-only member real, provider-enforced
// speaking permission; a provider failure rolls the grant back rather than being best-effort.

function groupVideoCallForGrantSpeakingAction(): array
{
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => true,
        // Explicit -- the factory defaults this to true, and anonymity now decides the granted capability.
        'anonymous' => false,
    ]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $otherCounsellorUser = User::factory()->create();
    $otherCounsellor = Counsellor::factory()->create(['user_id' => $otherCounsellorUser->id]);
    $groupTherapy->counsellors()->attach($otherCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
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

    return compact('creator', 'groupTherapy', 'counsellorUser', 'otherCounsellorUser', 'member', 'session', 'videoSession', 'memberParticipant');
}

function fakeVideoProviderRecordingGrantCapabilities(array &$calls, bool $shouldThrow = false)
{
    return new class($calls, $shouldThrow) implements VideoProviderInterface
    {
        public function __construct(private array &$calls, private bool $shouldThrow) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void
        {
            if ($this->shouldThrow) {
                throw new RuntimeException('Daily API is unreachable.');
            }

            $this->calls[] = [$user->id, $canSendAudio, $canSendVideo];
        }
    };
}

test('an active counsellor can grant a receive-only member speaking permission', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    $grant = GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($grant->video_session_participant_id)->toBe($data['memberParticipant']->id)
        ->and($grant->granted_by_user_id)->toBe($data['counsellorUser']->id)
        ->and($grant->isActive())->toBeTrue()
        ->and($grant->last_activity_at)->not->toBeNull()
        ->and($calls)->toBe([[$data['member']->id, true, true]]);
    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => $event->broadcastWith() === [
        'sessionId' => $data['session']->id,
        'userId' => $data['member']->id,
        'canSendAudio' => true,
        'canSendVideo' => true,
    ]);
});

test('any active counsellor can grant, not just one host', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    GrantSpeakingPermissionAction::new()->execute($data['session'], $data['otherCounsellorUser'], $data['member']->id);

    expect($data['memberParticipant']->currentSpeakingGrant())->not->toBeNull();
});

test('granting closes the member\'s open hand-raise and broadcasts that it was lowered', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $raise = $data['memberParticipant']->handRaises()->create(['raised_at' => now()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($raise->fresh()->lowered_at)->not->toBeNull();
    Event::assertDispatched(HandLoweredEvent::class);
});

test('granting without a raised hand works and broadcasts no hand-lowered event', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    Event::assertNotDispatched(HandLoweredEvent::class);
});

test('granting someone who already holds an open grant is idempotent -- no second row, no provider call', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $existing = VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $data['memberParticipant']->id,
        'revoked_at' => null,
    ]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    $grant = GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($grant->id)->toBe($existing->id)
        ->and($data['memberParticipant']->speakingGrants()->count())->toBe(1)
        ->and($calls)->toBe([]);
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

test('a provider failure rolls the grant back, keeps the hand raised, and surfaces a 502', function () {
    Event::fake();
    Log::shouldReceive('warning')->once();
    $data = groupVideoCallForGrantSpeakingAction();
    $raise = $data['memberParticipant']->handRaises()->create(['raised_at' => now()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls, true));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id))
        ->toThrow(fn (VideoException $e) => expect($e->getCode())->toBe(502));

    expect($data['memberParticipant']->speakingGrants()->count())->toBe(0)
        ->and($raise->fresh()->lowered_at)->toBeNull();
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
    Event::assertNotDispatched(HandLoweredEvent::class);
});

test('an ordinary member cannot grant speaking permission', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['member'], $data['counsellorUser']->id))
        ->toThrow(VideoException::class, 'Only a counsellor may change who can speak in this group video call.');
});

test('the group\'s creator (a client, not a counsellor) cannot grant speaking permission', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['creator'], $data['member']->id))
        ->toThrow(VideoException::class, 'Only a counsellor may change who can speak in this group video call.');
});

test('a counsellor cannot target themselves', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['counsellorUser']->id))
        ->toThrow(VideoException::class, 'You cannot change your own speaking permission.');
});

test('a participant who already has full access (another counsellor, or the creator) cannot be granted', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    foreach ([$data['otherCounsellorUser'], $data['creator']] as $fullAccessUser) {
        VideoSessionParticipant::factory()->create([
            'video_session_id' => $data['videoSession']->id,
            'participant_type' => User::class,
            'participant_id' => $fullAccessUser->id,
            'joined_at' => now(),
            'left_at' => null,
        ]);
    }
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['otherCounsellorUser']->id))
        ->toThrow(VideoException::class, 'This participant can already speak in this video call.');
    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['creator']->id))
        ->toThrow(VideoException::class, 'This participant can already speak in this video call.');
    expect($calls)->toBe([]);
});

// Same TT-3.2b/SCRUM-309 rule as removal: resolved only from this call's own live participants, so a
// nonexistent id and a real-but-absent id must produce the identical outcome.
test('a nonexistent target id gets the identical error as a real member who has left the call', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $data['memberParticipant']->update(['left_at' => now()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));
    $nonexistentUserId = User::max('id') + 1000;

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $nonexistentUserId))
        ->toThrow(VideoException::class, 'That person is not currently in this video call.');
    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id))
        ->toThrow(VideoException::class, 'That person is not currently in this video call.');
});

test('granting with no open video session is rejected', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $data['videoSession']->update(['ended_at' => now()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id))
        ->toThrow(VideoException::class, 'There is no active video call for this session.');
});

test('speaking permission is not available for a 1:1 Therapy video session', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($session, $counsellorUser, $client->id))
        ->toThrow(VideoException::class, 'Speaking permission is only available for group therapy video calls.');
});

test('a member who left the group but still has an open join row cannot be granted', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $data['groupTherapy']->users()->detach($data['member']->id);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id))
        ->toThrow(VideoException::class, 'That person is not currently in this video call.');
    expect($calls)->toBe([]);
});

test('an inactive counsellor of the group cannot grant', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $pendingCounsellorUser = User::factory()->create();
    $data['groupTherapy']->counsellors()->attach(Counsellor::factory()->create(['user_id' => $pendingCounsellorUser->id])->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $pendingCounsellorUser, $data['member']->id))
        ->toThrow(VideoException::class, 'Only a counsellor may change who can speak in this group video call.');
});

test('a counsellor of a different group cannot grant in this group\'s call', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $outsideCounsellorUser = User::factory()->create();
    $outsideGroup = GroupTherapy::factory()->create();
    $outsideGroup->counsellors()->attach(Counsellor::factory()->create(['user_id' => $outsideCounsellorUser->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $outsideCounsellorUser, $data['member']->id))
        ->toThrow(VideoException::class, 'Only a counsellor may change who can speak in this group video call.');
});

// TT-3.2f-g/SCRUM-324: anonymity-aware capability -- an anonymous member's camera is never enabled
// at the provider, whether the anonymity comes from the member's own pivot flag or the group's.
test('an anonymous member (own pivot flag) is granted audio-only', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['anonymous' => true]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($calls)->toBe([[$data['member']->id, true, false]]);
    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => $event->broadcastWith()['canSendVideo'] === false);
});

test('every member of an anonymous group is granted audio-only', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $data['groupTherapy']->update(['anonymous' => true]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($calls)->toBe([[$data['member']->id, true, false]]);
});

// TT-3.2f-g/SCRUM-324: interim hard-block until group-scoped guardian consent (SCRUM-313) exists.
test('a minor member cannot be granted speaking permission', function () {
    Event::fake();
    $data = groupVideoCallForGrantSpeakingAction();
    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['was_minor_at_join' => true]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id))
        ->toThrow(VideoException::class, 'Speaking permission is not yet available to a minor member in group therapy video.');

    expect($calls)->toBe([])
        ->and($data['memberParticipant']->speakingGrants()->count())->toBe(0);
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

// The join-time snapshot wins over today's age (the plain minor test above already covers an adult
// who joined as a minor staying blocked); a member with no snapshot (a row predating the column)
// falls back to a live age check.
test('the minor check uses the join-time snapshot, falling back to live age when there is none', function () {
    $data = groupVideoCallForGrantSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingGrantCapabilities($calls));

    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['was_minor_at_join' => null]);
    $data['member']->update(['dob' => now()->subYears(15)]);

    expect(fn () => GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id))
        ->toThrow(VideoException::class, 'Speaking permission is not yet available to a minor member in group therapy video.');

    $data['groupTherapy']->users()->updateExistingPivot($data['member']->id, ['was_minor_at_join' => false]);
    Event::fake();

    GrantSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($data['memberParticipant']->currentSpeakingGrant())->not->toBeNull();
});
