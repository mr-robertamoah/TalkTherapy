<?php

use App\Actions\Video\RevokeSpeakingPermissionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Events\SpeakingPermissionChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// TT-3.2f-f/SCRUM-323: the ONE shared revoke implementation -- a counsellor's manual revoke here,
// and the shared revokeGrant() core TT-3.2f-h (idle sweep) and TT-3.2f-i (removal) will call.

function groupVideoCallWithGrantForRevokeSpeakingAction(): array
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
    $grant = VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $memberParticipant->id,
        'granted_by_user_id' => $counsellorUser->id,
        'revoked_at' => null,
    ]);

    return compact('creator', 'counsellorUser', 'otherCounsellorUser', 'member', 'session', 'videoSession', 'memberParticipant', 'grant');
}

function fakeVideoProviderRecordingRevokeCapabilities(array &$calls, bool $shouldThrow = false)
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
                throw new RuntimeException('Chime API is unreachable.');
            }

            $this->calls[] = [$user->id, $canSendAudio, $canSendVideo];
        }
    };
}

test('any active counsellor can manually revoke a member\'s speaking permission', function () {
    Event::fake();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['otherCounsellorUser'], $data['member']->id);

    $grant = $data['grant']->fresh();
    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->revoked_by_user_id)->toBe($data['otherCounsellorUser']->id)
        ->and($grant->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::manual->value)
        ->and($calls)->toBe([[$data['member']->id, false, false]]);
    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => $event->broadcastWith() === [
        'sessionId' => $data['session']->id,
        'userId' => $data['member']->id,
        'canSendAudio' => false,
        'canSendVideo' => false,
        'reason' => SpeakingPermissionRevocationReasonEnum::manual->value,
    ]);
});

test('a manual revoke whose provider call fails leaves the grant open and surfaces a 502', function () {
    Event::fake();
    Log::shouldReceive('warning')->once();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls, true));

    expect(fn () => RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id))
        ->toThrow(fn (VideoException $e) => expect($e->getCode())->toBe(502));

    expect($data['grant']->fresh()->isActive())->toBeTrue();
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

test('an idle-expiry revoke whose provider call fails leaves the grant open so the next sweep retries', function () {
    Event::fake();
    Log::shouldReceive('warning')->once();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls, true));

    RevokeSpeakingPermissionAction::new()->revokeGrant($data['grant'], SpeakingPermissionRevocationReasonEnum::idle_auto_expiry);

    expect($data['grant']->fresh()->isActive())->toBeTrue();
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

test('a participant-removed revoke whose provider call fails still closes the grant locally', function () {
    Event::fake();
    Log::shouldReceive('warning')->once();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls, true));

    RevokeSpeakingPermissionAction::new()->revokeGrant($data['grant'], SpeakingPermissionRevocationReasonEnum::participant_removed);

    $grant = $data['grant']->fresh();
    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->revoked_by_user_id)->toBeNull()
        ->and($grant->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::participant_removed->value);
    Event::assertDispatched(SpeakingPermissionChangedEvent::class);
});

test('an automatic revoke succeeds with no acting user when the provider call works', function (SpeakingPermissionRevocationReasonEnum $reason) {
    Event::fake();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->revokeGrant($data['grant'], $reason);

    $grant = $data['grant']->fresh();
    expect($grant->revocation_reason)->toBe($reason->value)
        ->and($grant->revoked_by_user_id)->toBeNull()
        ->and($calls)->toBe([[$data['member']->id, false, false]]);
})->with([
    'idle auto-expiry' => [SpeakingPermissionRevocationReasonEnum::idle_auto_expiry],
    'participant removed' => [SpeakingPermissionRevocationReasonEnum::participant_removed],
]);

// The provider identifies people by user id, not join-cycle -- revoking a grant stranded on a stale
// join row must never mute the same member's CURRENT join, which may hold its own valid grant.
test('revoking a grant on a stale join row closes it locally without touching the provider', function () {
    Event::fake();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $data['memberParticipant']->update(['left_at' => now()]);
    $currentParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['member']->id,
        'joined_at' => now()->addSecond(),
        'left_at' => null,
    ]);
    $currentGrant = VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $currentParticipant->id,
        'revoked_at' => null,
    ]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->revokeGrant($data['grant'], SpeakingPermissionRevocationReasonEnum::idle_auto_expiry);

    expect($data['grant']->fresh()->isActive())->toBeFalse()
        ->and($currentGrant->fresh()->isActive())->toBeTrue()
        ->and($calls)->toBe([]);
    // The member's live join can still speak -- nothing must tell their client otherwise.
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

test('revoking an already-revoked grant is a no-op -- no provider call, no second broadcast', function () {
    Event::fake();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $data['grant']->update(['revoked_at' => now(), 'revocation_reason' => SpeakingPermissionRevocationReasonEnum::idle_auto_expiry->value]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->revokeGrant($data['grant'], SpeakingPermissionRevocationReasonEnum::manual, $data['counsellorUser']);

    expect($data['grant']->fresh()->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::idle_auto_expiry->value)
        ->and($calls)->toBe([]);
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

test('a manual revoke of a target who is not in the call at all is a safe no-op', function () {
    Event::fake();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], User::max('id') + 1000);

    expect($calls)->toBe([]);
    Event::assertNotDispatched(SpeakingPermissionChangedEvent::class);
});

test('a manual revoke with no open video session is a safe no-op', function () {
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $data['videoSession']->update(['ended_at' => now()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($calls)->toBe([]);
});

test('an ordinary member or the creator cannot revoke anyone\'s speaking permission', function () {
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    expect(fn () => RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['creator'], $data['member']->id))
        ->toThrow(VideoException::class, 'Only a counsellor may change who can speak in this group video call.');

    expect($data['grant']->fresh()->isActive())->toBeTrue();
});

test('a member cannot revoke their own permission through the counsellor path', function () {
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    expect(fn () => RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['member'], $data['member']->id))
        ->toThrow(VideoException::class);
});

// Security-review finding: after a reconnect, the live join row has no grant while the stale one
// still does -- a manual revoke must close the stale grant AND assert receive-only at the provider
// for the live join, never report "revoked" without touching the provider.
test('a manual revoke after the member reconnected closes the stale grant and still mutes them at the provider', function () {
    Event::fake();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    VideoSessionParticipant::factory()->create([
        'video_session_id' => $data['videoSession']->id,
        'participant_type' => User::class,
        'participant_id' => $data['member']->id,
        'joined_at' => now()->addSecond(),
        'left_at' => null,
    ]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($data['grant']->fresh()->isActive())->toBeFalse()
        ->and($calls)->toBe([[$data['member']->id, false, false]]);
    // Only the provider mute reaches the live join, so exactly one broadcast, from that mute.
    Event::assertDispatchedTimes(SpeakingPermissionChangedEvent::class, 1);
    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => $event->broadcastWith()['reason'] === SpeakingPermissionRevocationReasonEnum::manual->value);
});

test('a manual revoke of a live member with no open grant still asserts receive-only at the provider', function () {
    Event::fake();
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $data['grant']->update(['revoked_at' => now(), 'revocation_reason' => SpeakingPermissionRevocationReasonEnum::manual->value]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    RevokeSpeakingPermissionAction::new()->execute($data['session'], $data['counsellorUser'], $data['member']->id);

    expect($calls)->toBe([[$data['member']->id, false, false]]);
    // The provider mute changes the live join, so it's broadcast (with its reason) like any revoke.
    Event::assertDispatched(SpeakingPermissionChangedEvent::class, fn ($event) => $event->broadcastWith()['canSendAudio'] === false
        && $event->broadcastWith()['reason'] === SpeakingPermissionRevocationReasonEnum::manual->value);
});

test('a counsellor of a different group cannot revoke in this group\'s call', function () {
    $data = groupVideoCallWithGrantForRevokeSpeakingAction();
    $outsideCounsellorUser = User::factory()->create();
    $outsideGroup = GroupTherapy::factory()->create();
    $outsideGroup->counsellors()->attach(Counsellor::factory()->create(['user_id' => $outsideCounsellorUser->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingRevokeCapabilities($calls));

    expect(fn () => RevokeSpeakingPermissionAction::new()->execute($data['session'], $outsideCounsellorUser, $data['member']->id))
        ->toThrow(VideoException::class, 'Only a counsellor may change who can speak in this group video call.');
    expect($data['grant']->fresh()->isActive())->toBeTrue();
});
