<?php

use App\Actions\Video\JoinVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\ConstantsEnum;
use App\Events\VideoSessionStatusChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// TT-3.1a/SCRUM-274: the VideoProviderInterface is swapped for a fake in the container throughout
// -- this file pins down JoinVideoSessionAction's own orchestration, independent of any real
// provider's API.

function fakeVideoProvider(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public array $createRoomCalls = [];

        public array $createParticipantCredentialsCalls = [];

        public function createRoom(VideoSession $videoSession): array
        {
            $this->createRoomCalls[] = $videoSession->id;

            return ['room_id' => "fake-room-{$videoSession->id}", 'meta' => ['fake' => true]];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            $this->createParticipantCredentialsCalls[] = [$videoSession->id, $user->id, $displayName, $isOwner];

            return ['token' => "fake-token-{$user->id}"];
        }

        public function endRoom(VideoSession $videoSession): void {}
    };
}

function onlineInSessionTherapySessionForJoin(array $overrides = []): Session
{
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return Session::factory()->create(array_merge([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ], $overrides));
}

test('joining creates a new VideoSession, calls the provider to create a room, and records the participant', function () {
    Event::fake();
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    $credentials = JoinVideoSessionAction::new()->execute($session, $client);

    $videoSession = VideoSession::query()->where('session_id', $session->id)->first();
    expect($videoSession)->not->toBeNull()
        ->and($videoSession->provider_room_id)->toBe("fake-room-{$videoSession->id}")
        ->and($fakeProvider->createRoomCalls)->toBe([$videoSession->id])
        ->and($credentials)->toBe(['token' => "fake-token-{$client->id}"]);

    $this->assertDatabaseHas('video_session_participants', [
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $client->id,
    ]);

    Event::assertDispatched(VideoSessionStatusChangedEvent::class);
});

test('a second join by a different participant reuses the same VideoSession rather than creating a new room', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $counsellorUser = $session->for->counsellor->user;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);
    JoinVideoSessionAction::new()->execute($session, $counsellorUser);

    expect(VideoSession::query()->where('session_id', $session->id)->count())->toBe(1)
        ->and($fakeProvider->createRoomCalls)->toHaveCount(1);

    $this->assertDatabaseHas('video_session_participants', [
        'participant_id' => $counsellorUser->id,
    ]);
});

test('the assigned counsellor joins as owner, the client does not', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $counsellorUser = $session->for->counsellor->user;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);
    JoinVideoSessionAction::new()->execute($session, $counsellorUser);

    [, , , $clientIsOwner] = $fakeProvider->createParticipantCredentialsCalls[0];
    [, , , $counsellorIsOwner] = $fakeProvider->createParticipantCredentialsCalls[1];

    expect($clientIsOwner)->toBeFalse()
        ->and($counsellorIsOwner)->toBeTrue();
});

test('joining is blocked by the same availability gate as EnsureVideoIsAvailableForSessionAction', function () {
    $session = onlineInSessionTherapySessionForJoin(['status' => 'PENDING']);
    $client = $session->for->addedby;
    app()->instance(VideoProviderInterface::class, fakeVideoProvider());

    expect(fn () => JoinVideoSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class);

    $this->assertDatabaseCount('video_sessions', 0);
});

// TT-3.1c/SCRUM-276 QA finding: createRoom() was unguarded, so a real provider HTTP failure
// (Illuminate\Http\Client\RequestException, whose getCode() equals the upstream's own status,
// not 500) sailed straight past ResolvesExceptionResponse::messageFor()'s 500-only masking and
// leaked the provider's raw response body to the end user.
test('a provider room-creation failure surfaces a safe, generic message, never the raw provider error', function () {
    Log::shouldReceive('warning')->once();
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            throw new RuntimeException('{"error":"authorization-header-error","info":"invalid authorization header"}');
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}
    });

    try {
        JoinVideoSessionAction::new()->execute($session, $client);
        $this->fail('Expected a VideoException to be thrown.');
    } catch (VideoException $exception) {
        expect($exception->getMessage())
            ->toBe('Unable to start the video call right now. Please try again shortly.')
            ->not->toContain('authorization-header-error');
    }

    // The transaction rolls back the whole find-or-create -- no half-created VideoSession left
    // behind for a future join to stumble over.
    $this->assertDatabaseCount('video_sessions', 0);
});

// A room that has already ended (e.g. a prior call was explicitly ended) must not be silently
// rejoined -- a fresh join after an end should start a genuinely new epoch/room.
test('joining after the video session has ended creates a fresh VideoSession, not the ended one', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    $endedVideoSession = VideoSession::factory()->create([
        'session_id' => $session->id,
        'ended_at' => now()->subMinute(),
    ]);

    JoinVideoSessionAction::new()->execute($session, $client);

    $newVideoSession = VideoSession::query()->where('session_id', $session->id)->whereNull('ended_at')->first();
    expect($newVideoSession)->not->toBeNull()
        ->and($newVideoSession->id)->not->toBe($endedVideoSession->id);
});

// Security-review finding (2026-09-11): the joining client's real name must never reach the
// provider (and therefore the counsellor's on-screen video label) when the therapy is anonymous.
test('an anonymous therapy\'s client joins video under the anonymous label, never their real name', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $session->for->update(['anonymous' => true]);
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($displayName)->not->toBe($client->name);
});

// The counsellor's own identity is never masked -- anonymity only ever applies to the client
// (TherapyTrait::addedByUserIsMaskedFor()'s own established rule).
test('an anonymous therapy\'s counsellor still joins video under their own real name', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $session->for->update(['anonymous' => true]);
    $counsellorUser = $session->for->counsellor->user;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $counsellorUser);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe($counsellorUser->name);
});

// A non-anonymous therapy's client joins under their own real name, unaffected by this fix.
// TherapyFactory defaults `anonymous` to true, so this must override it explicitly.
test('a non-anonymous therapy\'s client joins video under their own real name', function () {
    $session = onlineInSessionTherapySessionForJoin();
    $session->for->update(['anonymous' => false]);
    $client = $session->for->addedby;
    $fakeProvider = fakeVideoProvider();
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    JoinVideoSessionAction::new()->execute($session, $client);

    [, , $displayName] = $fakeProvider->createParticipantCredentialsCalls[0];
    expect($displayName)->toBe($client->name);
});
