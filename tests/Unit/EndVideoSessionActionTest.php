<?php

use App\Actions\Video\EndVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Events\VideoSessionStatusChangedEvent;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\Event;

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
