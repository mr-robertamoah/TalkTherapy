<?php

use App\Actions\Video\EndVideoSessionAction;
use App\Actions\Video\JoinVideoSessionAction;
use App\Actions\Video\LeaveVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;

// TT-3.1d/SCRUM-277: this ticket's own mandatory regression -- a transient media disconnect (and
// the resulting reconnect, which the frontend implements as a plain re-join of the same still-open
// VideoSession epoch, see useVideoSession.js's handleDisconnected()) must NEVER change
// Session.status. The disconnect detection itself is frontend-only (no JS test runner exists in
// this codebase, matching every other composable here -- see useVideoSession.js's own review
// notes), so this proves the invariant that actually matters at the layer that can enforce it:
// repeatedly calling Join/Leave/EndVideoSessionAction (exactly what a disconnect-then-reconnect
// cycle does from the backend's point of view) never touches Session.status.
//
// Review finding (2026-09-11): NOT parametrized over both providers via config('video.provider')
// -- app()->instance(VideoProviderInterface::class, ...) below overrides the container binding
// directly, bypassing VideoServiceProvider's config-driven bind() entirely, so a 'daily'/'chime'
// dataset would run byte-for-byte the same code twice with no differential coverage. Mirrors
// JoinVideoSessionActionTest.php's own established convention of omitting that parametrization
// for the identical reason: this action layer's own orchestration logic is provider-agnostic by
// construction (provider selection only ever affects which concrete class the container resolves,
// never anything Session.status-related).

function fakeVideoProviderForStatusInvariantTest(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => "fake-room-{$videoSession->id}", 'meta' => ['fake' => true]];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return ['token' => "fake-token-{$user->id}"];
        }

        public function endRoom(VideoSession $videoSession): void {}
    };
}

function onlineInSessionTherapySessionForStatusInvariant(): Session
{
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
}

test('a full disconnect-then-reconnect cycle (leave, then re-join the same epoch) never mutates Session.status', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForStatusInvariantTest());
    $session = onlineInSessionTherapySessionForStatusInvariant();
    $client = $session->for->addedby;
    $originalStatus = $session->status;

    JoinVideoSessionAction::new()->execute($session, $client);
    expect($session->fresh()->status)->toBe($originalStatus);

    // Simulates the local user's own connection dropping -- useVideoSession.js's
    // handleDisconnected() deliberately never calls sessions.video.leave/end for this (the
    // VideoSession epoch must stay open so the SAME room can be rejoined), but proving the
    // invariant holds even for an explicit self-leave is the stronger, more defensive test.
    LeaveVideoSessionAction::new()->execute($session, $client);
    expect($session->fresh()->status)->toBe($originalStatus);

    // The "reconnect": a fresh JoinVideoSessionAction call, reusing the still-open VideoSession
    // epoch (nothing ended it) -- exactly what useVideoSession.js's own rejoin does.
    JoinVideoSessionAction::new()->execute($session, $client);
    expect($session->fresh()->status)->toBe($originalStatus);

    // Multiple disconnect/reconnect cycles in a row -- not just a single one.
    LeaveVideoSessionAction::new()->execute($session, $client);
    JoinVideoSessionAction::new()->execute($session, $client);
    expect($session->fresh()->status)->toBe($originalStatus);

    expect(VideoSession::query()->where('session_id', $session->id)->count())
        ->toBe(1, 'a disconnect/reconnect cycle must reuse the same VideoSession epoch, never create a new one');
});

test('explicitly ending the video call never mutates Session.status either', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForStatusInvariantTest());
    $session = onlineInSessionTherapySessionForStatusInvariant();
    $client = $session->for->addedby;
    $originalStatus = $session->status;

    JoinVideoSessionAction::new()->execute($session, $client);
    EndVideoSessionAction::new()->execute($session, $client);

    expect($session->fresh()->status)->toBe($originalStatus);
});
