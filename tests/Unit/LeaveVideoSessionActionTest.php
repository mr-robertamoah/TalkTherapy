<?php

use App\Actions\Video\LeaveVideoSessionAction;
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
    $session = Session::factory()->create();

    LeaveVideoSessionAction::new()->execute($session, $client);
})->throwsNoExceptions();

test('leaving only affects the participant\'s own most recent join row, not an earlier one already left', function () {
    $client = User::factory()->create();
    $session = Session::factory()->create();
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
