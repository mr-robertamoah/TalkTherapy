<?php

use App\Actions\Video\LeaveVideoSessionAction;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;

test('leaving records the calling user\'s own left_at without affecting the other participant', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id]);
    $clientParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $client->id,
    ]);
    $counsellorParticipant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $counsellorUser->id,
    ]);

    LeaveVideoSessionAction::new()->execute($session, $client);

    expect($clientParticipant->fresh()->left_at)->not->toBeNull()
        ->and($counsellorParticipant->fresh()->left_at)->toBeNull();

    // The room itself is untouched by a self-leave -- only EndVideoSessionAction ends it.
    expect($videoSession->fresh()->ended_at)->toBeNull();
});

test('leaving when there is no open video session at all is a safe no-op', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    LeaveVideoSessionAction::new()->execute($session, $client);
})->throwsNoExceptions();

test('leaving only affects the participant\'s own most recent join row, not an earlier one already left', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id]);
    $oldRow = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $client->id,
        'joined_at' => now()->subMinutes(10),
        'left_at' => now()->subMinutes(5),
    ]);
    $currentRow = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id,
        'participant_type' => User::class,
        'participant_id' => $client->id,
        'joined_at' => now()->subMinute(),
        'left_at' => null,
    ]);

    LeaveVideoSessionAction::new()->execute($session, $client);

    expect($currentRow->fresh()->left_at)->not->toBeNull()
        ->and($oldRow->fresh()->left_at->timestamp)->toBe($oldRow->left_at->timestamp);
});

// TT-3.1b/SCRUM-275: security-review finding on TT-3.1a -- this action took no authorization
// check of its own, safe only because nothing called it yet. Now that VideoSessionController
// derives $user from auth()->user() and calls this directly, a genuine non-participant must be
// rejected. (Every test in this file uses a real Therapy for exactly this reason: a second
// security-review finding caught Session::isNotParticipant() itself failing OPEN -- returning
// null/falsy rather than true -- when `for` doesn't resolve, e.g. Session::factory()'s own
// default for_id/for_type with no matching row; fixed at the model level, but tests here still
// use a real participant relationship rather than relying on that edge case either way.)
test('a non-participant cannot leave another pair\'s video call', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id]);
    $outsider = User::factory()->create();

    expect(fn () => LeaveVideoSessionAction::new()->execute($session, $outsider))
        ->toThrow(VideoException::class);

    expect($videoSession->fresh()->ended_at)->toBeNull();
});
