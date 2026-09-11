<?php

use App\Actions\VideoConsent\GetVideoConsentAuditTrailForWardAction;
use App\Exceptions\VideoConsentException;
use App\Models\Guardianship;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-b/SCRUM-281: every guardian of the ward can see the full history, not just the
// guardian who happened to act.

test('a guardian can read the ward\'s full consent audit trail', function () {
    $ward = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    $consent = VideoConsent::factory()->create(['ward_id' => $ward->id, 'guardian_id' => $guardian->id]);

    $trail = GetVideoConsentAuditTrailForWardAction::new()->execute($guardian, $ward);

    expect($trail)->toHaveCount(1)
        ->and($trail->first()->id)->toBe($consent->id);
});

test('a co-guardian who did not act can still see who granted consent', function () {
    $ward = User::factory()->create();
    $actingGuardian = User::factory()->create();
    $coGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $actingGuardian->id, 'ward_id' => $ward->id]);
    Guardianship::query()->create(['guardian_id' => $coGuardian->id, 'ward_id' => $ward->id]);
    VideoConsent::factory()->create(['ward_id' => $ward->id, 'guardian_id' => $actingGuardian->id]);

    $trail = GetVideoConsentAuditTrailForWardAction::new()->execute($coGuardian, $ward);

    expect($trail)->toHaveCount(1)
        ->and($trail->first()->guardian_id)->toBe($actingGuardian->id)
        ->and($trail->first()->guardian->is($actingGuardian))->toBeTrue();
});

test('a non-guardian cannot read the audit trail', function () {
    $ward = User::factory()->create();
    $unrelatedUser = User::factory()->create();
    VideoConsent::factory()->create(['ward_id' => $ward->id]);

    expect(fn () => GetVideoConsentAuditTrailForWardAction::new()->execute($unrelatedUser, $ward))
        ->toThrow(VideoConsentException::class, 'You are not a guardian of this client.');
});

test('the audit trail includes both granted and revoked history, newest first', function () {
    $ward = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    $older = VideoConsent::factory()->revoked()->create([
        'ward_id' => $ward->id,
        'guardian_id' => $guardian->id,
        'granted_at' => now()->subDay(),
    ]);
    $newer = VideoConsent::factory()->create([
        'ward_id' => $ward->id,
        'guardian_id' => $guardian->id,
        'granted_at' => now(),
    ]);

    $trail = GetVideoConsentAuditTrailForWardAction::new()->execute($guardian, $ward);

    expect($trail->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

test('the audit trail does not leak another ward\'s consent history', function () {
    $ward = User::factory()->create();
    $otherWard = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $otherWard->id]);
    VideoConsent::factory()->create(['ward_id' => $ward->id]);
    VideoConsent::factory()->create(['ward_id' => $otherWard->id]);

    $trail = GetVideoConsentAuditTrailForWardAction::new()->execute($guardian, $ward);

    expect($trail)->toHaveCount(1)
        ->and($trail->first()->ward_id)->toBe($ward->id);
});
