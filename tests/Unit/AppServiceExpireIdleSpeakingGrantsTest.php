<?php

use App\Actions\Video\RevokeSpeakingPermissionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Events\SpeakingPermissionChangedEvent;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;
use App\Services\AppService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// TT-3.2f-h/SCRUM-325: the scheduled sweep reverting idle speaking grants through the shared revoke.

function liveGrantForIdleSweep(array $grantOverrides = [], array $videoSessionOverrides = []): VideoSessionSpeakingGrant
{
    $member = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
    $videoSession = VideoSession::factory()->create(array_merge(['session_id' => $session->id, 'provider_room_id' => 'room-1'], $videoSessionOverrides));
    $participant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $member->id,
        'joined_at' => now()->subHour(),
        'left_at' => null,
    ]);

    return VideoSessionSpeakingGrant::factory()->create(array_merge([
        'video_session_participant_id' => $participant->id,
        'granted_at' => now()->subHour(),
        'last_activity_at' => now(),
        'revoked_at' => null,
    ], $grantOverrides));
}

function fakeVideoProviderRecordingIdleSweep(array &$calls, bool $shouldThrow = false)
{
    return new class($calls, $shouldThrow) implements VideoProviderInterface
    {
        public function __construct(private array &$calls, private bool $shouldThrow) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false): array
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

beforeEach(function () {
    $this->freezeTime();
    config(['video.speaking_grant_idle_timeout' => 300]);
});

test('a grant with no activity past the timeout is revoked as idle_auto_expiry and the member muted', function () {
    Event::fake();
    $grant = liveGrantForIdleSweep(['last_activity_at' => now()->subSeconds(301)]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));

    AppService::new()->expireIdleSpeakingGrants();

    $grant = $grant->fresh();
    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::idle_auto_expiry->value)
        ->and($grant->revoked_by_user_id)->toBeNull()
        ->and($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBeFalse();
    Event::assertDispatched(SpeakingPermissionChangedEvent::class);
});

test('a grant with recent activity is left alone', function () {
    $grant = liveGrantForIdleSweep(['last_activity_at' => now()->subSeconds(299)]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));

    AppService::new()->expireIdleSpeakingGrants();

    expect($grant->fresh()->isActive())->toBeTrue()
        ->and($calls)->toBe([]);
});

test('the timeout comes from config, not a hardcoded value', function () {
    config(['video.speaking_grant_idle_timeout' => 60]);
    $grant = liveGrantForIdleSweep(['last_activity_at' => now()->subSeconds(90)]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));

    AppService::new()->expireIdleSpeakingGrants();

    expect($grant->fresh()->isActive())->toBeFalse();
});

test('a grant with no activity timestamp at all falls back to its grant time', function () {
    $stale = liveGrantForIdleSweep(['last_activity_at' => null, 'granted_at' => now()->subSeconds(400)]);
    $fresh = liveGrantForIdleSweep(['last_activity_at' => null, 'granted_at' => now()->subSeconds(100)]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));

    AppService::new()->expireIdleSpeakingGrants();

    expect($stale->fresh()->isActive())->toBeFalse()
        ->and($fresh->fresh()->isActive())->toBeTrue();
});

test('already-revoked grants and grants in an ended call are not touched', function () {
    $revoked = liveGrantForIdleSweep(['last_activity_at' => now()->subHour(), 'revoked_at' => now()->subMinutes(30), 'revocation_reason' => SpeakingPermissionRevocationReasonEnum::manual->value]);
    $inEndedCall = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()], ['ended_at' => now()->subMinutes(10)]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));

    AppService::new()->expireIdleSpeakingGrants();

    expect($revoked->fresh()->revocation_reason)->toBe(SpeakingPermissionRevocationReasonEnum::manual->value)
        ->and($inEndedCall->fresh()->isActive())->toBeTrue()
        ->and($calls)->toBe([]);
});

// Per revokeGrant()'s idle_auto_expiry rule: a provider failure keeps the grant open so the next run
// retries, rather than stranding a member still able to publish with no open grant left to revoke.
test('a provider failure leaves the grant open for the next sweep to retry', function () {
    Log::shouldReceive('warning')->once();
    $grant = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls, true));

    AppService::new()->expireIdleSpeakingGrants();

    expect($grant->fresh()->isActive())->toBeTrue();
});

test('one grant failing does not stop the sweep from revoking the others', function () {
    Event::fake();
    $broken = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()]);
    $healthy = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()]);
    // Point the first grant's join row at a video session that no longer exists, so revokeGrant()
    // blows up on it specifically -- the sweep runs in id order, so the broken one is hit first.
    $broken->videoSessionParticipant->videoSession()->update(['provider_room_id' => null]);
    $calls = [];
    $provider = new class($calls) implements VideoProviderInterface
    {
        public function __construct(private array &$calls) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void
        {
            if (! $videoSession->provider_room_id) {
                throw new LogicException('Unexpected state.');
            }

            $this->calls[] = $user->id;
        }
    };
    app()->instance(VideoProviderInterface::class, $provider);
    // revokedAtProvider() swallows provider exceptions for idle_auto_expiry (leaves the grant open),
    // so this exercises the "provider fails -> retried next run" path for the broken grant, and
    // proves the healthy one is still processed after it.
    Log::shouldReceive('warning')->once();

    AppService::new()->expireIdleSpeakingGrants();

    expect($broken->fresh()->isActive())->toBeTrue()
        ->and($healthy->fresh()->isActive())->toBeFalse();
});

test('an unexpected error on one grant is logged with its id and the sweep continues', function () {
    Event::fake();
    $broken = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()]);
    $healthy = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()]);
    // A dangling participant id (no such user, even trashed) makes revokeGrant() throw outright.
    $broken->videoSessionParticipant->update(['participant_id' => User::withTrashed()->max('id') + 1000]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));
    Log::shouldReceive('error')->once()->withArgs(fn ($message, $context) => $context['grantId'] === $broken->id);

    AppService::new()->expireIdleSpeakingGrants();

    expect($broken->fresh()->isActive())->toBeTrue()
        ->and($healthy->fresh()->isActive())->toBeFalse();
});

// Security-review L1: a soft-deleted user's grant must still be closed (and muted), not throw forever.
test('a soft-deleted member\'s idle grant is still revoked', function () {
    Event::fake();
    $grant = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()]);
    User::query()->whereKey($grant->videoSessionParticipant->participant_id)->first()->delete();
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));

    AppService::new()->expireIdleSpeakingGrants();

    expect($grant->fresh()->isActive())->toBeFalse()
        ->and($calls)->toHaveCount(1);
});

// Security-review L2: a ping landing after the sweep's own query must stop the revoke.
test('a grant pinged after the sweep selected it is not revoked', function () {
    $grant = liveGrantForIdleSweep(['last_activity_at' => now()->subHour()]);
    $calls = [];
    app()->instance(VideoProviderInterface::class, fakeVideoProviderRecordingIdleSweep($calls));
    $staleSnapshot = $grant->fresh();
    $grant->update(['last_activity_at' => now()]);

    RevokeSpeakingPermissionAction::new()->revokeGrant(
        $staleSnapshot, SpeakingPermissionRevocationReasonEnum::idle_auto_expiry, idleCutoff: now()->subSeconds(300)
    );

    expect($grant->fresh()->isActive())->toBeTrue()
        ->and($calls)->toBe([]);
});
