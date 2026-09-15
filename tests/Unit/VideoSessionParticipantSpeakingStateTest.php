<?php

use App\Models\VideoSessionHandRaise;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;

// TT-3.2f-b/SCRUM-319: VideoSessionParticipant's own convenience accessors onto its speaking-grant
// and hand-raise history.

test('currentSpeakingGrant returns the latest still-open grant, ignoring a prior revoked one', function () {
    $participant = VideoSessionParticipant::factory()->create();
    VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $participant->id,
        'granted_at' => now()->subMinutes(10),
        'revoked_at' => now()->subMinutes(5),
    ]);
    $active = VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $participant->id,
        'granted_at' => now(),
        'revoked_at' => null,
    ]);

    expect($participant->currentSpeakingGrant()?->id)->toBe($active->id);
});

test('currentSpeakingGrant returns null when there is no open grant at all', function () {
    $participant = VideoSessionParticipant::factory()->create();
    VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $participant->id,
        'revoked_at' => now(),
    ]);

    expect($participant->currentSpeakingGrant())->toBeNull();
});

test('currentHandRaise returns the latest still-raised hand, ignoring a prior lowered one', function () {
    $participant = VideoSessionParticipant::factory()->create();
    VideoSessionHandRaise::factory()->create([
        'video_session_participant_id' => $participant->id,
        'raised_at' => now()->subMinutes(10),
        'lowered_at' => now()->subMinutes(5),
    ]);
    $active = VideoSessionHandRaise::factory()->create([
        'video_session_participant_id' => $participant->id,
        'raised_at' => now(),
        'lowered_at' => null,
    ]);

    expect($participant->currentHandRaise()?->id)->toBe($active->id);
});

test('currentHandRaise returns null when there is no open raise at all', function () {
    $participant = VideoSessionParticipant::factory()->create();
    VideoSessionHandRaise::factory()->create([
        'video_session_participant_id' => $participant->id,
        'lowered_at' => now(),
    ]);

    expect($participant->currentHandRaise())->toBeNull();
});
