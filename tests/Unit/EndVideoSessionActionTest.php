<?php

use App\Actions\Video\EndVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Events\VideoSessionStatusChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// TT-3.1c/SCRUM-276 QA finding: the "best-effort" comment above endRoom()'s call site previously
// described intent the code didn't implement -- there was no try/catch, so a provider failure
// DID propagate and block the local ended_at update, contradicting VideoProviderInterface's own
// documented contract.
test('a provider-side teardown failure still marks the room ended locally, matching endRoom()\'s best-effort contract', function () {
    Event::fake();
    Log::shouldReceive('warning')->once();
    $session = Session::factory()->create();
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);
    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void
        {
            throw new RuntimeException('Daily API is unreachable.');
        }
    });

    EndVideoSessionAction::new()->execute($session);

    expect($videoSession->fresh()->ended_at)->not->toBeNull();
    Event::assertDispatched(VideoSessionStatusChangedEvent::class);
});

test('ending marks the room ended, marks every still-active participant left, and calls the provider to tear it down', function () {
    Event::fake();
    $session = Session::factory()->create();
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);
    $activeParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'left_at' => null,
    ]);
    $alreadyLeftParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'left_at' => now()->subMinutes(5),
    ]);

    $fakeProvider = new class implements VideoProviderInterface
    {
        public array $endRoomCalls = [];

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void
        {
            $this->endRoomCalls[] = $videoSession->id;
        }
    };
    app()->instance(VideoProviderInterface::class, $fakeProvider);

    EndVideoSessionAction::new()->execute($session);

    expect($videoSession->fresh()->ended_at)->not->toBeNull()
        ->and($activeParticipant->fresh()->left_at)->not->toBeNull()
        ->and($alreadyLeftParticipant->fresh()->left_at->timestamp)->toBe($alreadyLeftParticipant->left_at->timestamp)
        ->and($fakeProvider->endRoomCalls)->toBe([$videoSession->id]);

    Event::assertDispatched(VideoSessionStatusChangedEvent::class);
});

test('ending when there is no open video session at all is a safe no-op', function () {
    $session = Session::factory()->create();
    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void
        {
            throw new RuntimeException('endRoom should never be called when there is nothing to end.');
        }
    });

    EndVideoSessionAction::new()->execute($session);
})->throwsNoExceptions();

// TT-3.1b/SCRUM-275: security-review finding on TT-3.1a -- this action took no $user/authorization
// check at all, so anyone holding a Session object could end another pair's call. $user is
// optional (see the action's own comment on why), but when given, must be a participant.
test('a non-participant cannot end another pair\'s video call', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);
    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void
        {
            throw new RuntimeException('endRoom should never be called when the caller is not authorized.');
        }
    });
    $outsider = User::factory()->create();

    expect(fn () => EndVideoSessionAction::new()->execute($session, $outsider))
        ->toThrow(VideoException::class);

    expect($videoSession->fresh()->ended_at)->toBeNull();
});

test('a session participant can end the call', function () {
    Event::fake();
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-1']);
    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}
    });

    EndVideoSessionAction::new()->execute($session, $client);

    expect($videoSession->fresh()->ended_at)->not->toBeNull();
});

test('ending an already-ended video session again is a safe no-op (does not re-call the provider)', function () {
    $session = Session::factory()->create();
    VideoSession::factory()->create(['session_id' => $session->id, 'ended_at' => now()->subMinute()]);

    app()->instance(VideoProviderInterface::class, new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void
        {
            throw new RuntimeException('endRoom should never be called on an already-ended VideoSession.');
        }
    });

    EndVideoSessionAction::new()->execute($session);
})->throwsNoExceptions();
