<?php

use App\Models\VideoSessionHandRaise;
use App\Models\VideoSessionParticipant;

// TT-3.2f-b/SCRUM-319: mutable current-state hand-raise tracking (deliberately NOT append-only,
// unlike VideoSessionSpeakingGrant -- see this model's own comment for why).

test('isActive is true for a raise with no lowered_at', function () {
    $handRaise = VideoSessionHandRaise::factory()->create(['lowered_at' => null]);

    expect($handRaise->isActive())->toBeTrue();
});

test('isActive is false once lowered_at is set', function () {
    $handRaise = VideoSessionHandRaise::factory()->create(['lowered_at' => now()]);

    expect($handRaise->isActive())->toBeFalse();
});

test('scopeWhereActiveFor finds only the currently-raised hand for a participant, not a prior lowered one', function () {
    $participant = VideoSessionParticipant::factory()->create();
    $lowered = VideoSessionHandRaise::factory()->create([
        'video_session_participant_id' => $participant->id,
        'lowered_at' => now()->subMinute(),
    ]);
    $active = VideoSessionHandRaise::factory()->create([
        'video_session_participant_id' => $participant->id,
        'lowered_at' => null,
    ]);

    $result = VideoSessionHandRaise::query()->whereActiveFor($participant->id)->get();

    expect($result->pluck('id')->all())->toBe([$active->id])
        ->and($result->pluck('id')->all())->not->toContain($lowered->id);
});
