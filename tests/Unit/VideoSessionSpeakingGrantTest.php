<?php

use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;

// TT-3.2f-b/SCRUM-319: append-only grant/revoke audit trail, modeled on VideoConsent's own shape.

test('isActive is true for a grant with no revoked_at', function () {
    $grant = VideoSessionSpeakingGrant::factory()->create(['revoked_at' => null]);

    expect($grant->isActive())->toBeTrue();
});

test('isActive is false once revoked_at is set', function () {
    $grant = VideoSessionSpeakingGrant::factory()->create(['revoked_at' => now()]);

    expect($grant->isActive())->toBeFalse();
});

test('scopeWhereActiveFor finds only the currently-open grant for a participant, not a prior revoked one', function () {
    $participant = VideoSessionParticipant::factory()->create();
    $revoked = VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $participant->id,
        'revoked_at' => now()->subMinute(),
    ]);
    $active = VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $participant->id,
        'revoked_at' => null,
    ]);

    $result = VideoSessionSpeakingGrant::query()->whereActiveFor($participant->id)->get();

    expect($result->pluck('id')->all())->toBe([$active->id])
        ->and($result->pluck('id')->all())->not->toContain($revoked->id);
});

test('scopeWhereActiveFor scopes to the given participant only, not any other participant\'s grant', function () {
    $participant = VideoSessionParticipant::factory()->create();
    $otherParticipant = VideoSessionParticipant::factory()->create();
    VideoSessionSpeakingGrant::factory()->create([
        'video_session_participant_id' => $otherParticipant->id,
        'revoked_at' => null,
    ]);

    $result = VideoSessionSpeakingGrant::query()->whereActiveFor($participant->id)->get();

    expect($result)->toBeEmpty();
});
