<?php

use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-a/SCRUM-280: schema/model-level tests only -- no enforcement/behavior yet (that's
// TT-3.1e-d's job). Proves the relationships, the "current grant" derivation, and that both
// Therapy (PER_THERAPY) and Session (PER_SESSION) work as the polymorphic consentable.

test('a VideoConsent resolves its ward and guardian relations', function () {
    $ward = User::factory()->create();
    $guardian = User::factory()->create();
    $consent = VideoConsent::factory()->create([
        'ward_id' => $ward->id,
        'guardian_id' => $guardian->id,
    ]);

    expect($consent->ward->is($ward))->toBeTrue()
        ->and($consent->guardian->is($guardian))->toBeTrue();
});

test('a VideoConsent can be scoped to a Therapy (PER_THERAPY)', function () {
    $therapy = Therapy::factory()->create();
    $consent = VideoConsent::factory()->create([
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);

    expect($consent->consentable)->toBeInstanceOf(Therapy::class)
        ->and($consent->consentable->is($therapy))->toBeTrue()
        ->and($therapy->videoConsents()->first()->is($consent))->toBeTrue();
});

test('a VideoConsent can be scoped to a Session (PER_SESSION)', function () {
    $session = Session::factory()->create();
    $consent = VideoConsent::factory()->create([
        'consentable_type' => Session::class,
        'consentable_id' => $session->id,
    ]);

    expect($consent->consentable)->toBeInstanceOf(Session::class)
        ->and($consent->consentable->is($session))->toBeTrue()
        ->and($session->videoConsents()->first()->is($consent))->toBeTrue();
});

test('isValid is true for a freshly granted consent and false once revoked', function () {
    $consent = VideoConsent::factory()->create();
    expect($consent->isValid())->toBeTrue();

    $consent->update(['revoked_at' => now()]);
    expect($consent->fresh()->isValid())->toBeFalse();
});

test('a re-granted consent for the same scope after revocation is a new row, not a mutated one', function () {
    $ward = User::factory()->create();
    $therapy = Therapy::factory()->create();
    $revoked = VideoConsent::factory()->revoked()->create([
        'ward_id' => $ward->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);
    $reGranted = VideoConsent::factory()->create([
        'ward_id' => $ward->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);

    expect($revoked->id)->not->toBe($reGranted->id)
        ->and($revoked->fresh()->isValid())->toBeFalse()
        ->and($reGranted->fresh()->isValid())->toBeTrue()
        ->and(
            $therapy->videoConsents()->whereNull('revoked_at')->count()
        )->toBe(1, 'exactly one currently-valid grant for this scope, the old revoked row stays as history');
});

// Review finding (2026-09-11): the revoked() factory state used to resolve its guardian-reuse
// fallback against the factory's OWN definition()-generated guardian_id, not one overridden at
// the create() call site -- verified via a throwaway probe that this silently produced an
// unrelated revoker. Fixed via afterCreating(); this test proves the fix by overriding
// guardian_id alongside ->revoked() in the same call, exactly the pattern that was broken.
test('the revoked() factory state reuses the actually-persisted guardian_id, even when overridden at create() time', function () {
    $specificGuardian = User::factory()->create();

    $consent = VideoConsent::factory()->revoked()->create(['guardian_id' => $specificGuardian->id]);

    expect($consent->guardian_id)->toBe($specificGuardian->id)
        ->and($consent->revoked_by_guardian_id)->toBe($specificGuardian->id);
});

// Review finding (2026-09-11): standard Eloquent morphMany behavior, but not previously proven
// against an actual id collision between the two consentable types -- Therapy and Session ids
// are independent auto-increment sequences, so nothing stops them sharing a numeric id in a
// fresh test run.
test('Therapy::videoConsents() and Session::videoConsents() stay isolated even when the two consentables share the same numeric id', function () {
    $therapy = Therapy::factory()->create();
    $session = Session::factory()->create(['id' => $therapy->id]);

    $therapyConsent = VideoConsent::factory()->create([
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);
    $sessionConsent = VideoConsent::factory()->create([
        'consentable_type' => Session::class,
        'consentable_id' => $session->id,
    ]);

    expect($therapy->videoConsents()->pluck('id')->all())->toBe([$therapyConsent->id])
        ->and($session->videoConsents()->pluck('id')->all())->toBe([$sessionConsent->id]);
});

test('revoked_by_guardian_id records who revoked, distinct from who granted', function () {
    $granter = User::factory()->create();
    $otherGuardian = User::factory()->create();
    $consent = VideoConsent::factory()->create(['guardian_id' => $granter->id]);

    $consent->update(['revoked_at' => now(), 'revoked_by_guardian_id' => $otherGuardian->id]);

    expect($consent->fresh()->guardian->is($granter))->toBeTrue()
        ->and($consent->fresh()->revokedByGuardian->is($otherGuardian))->toBeTrue();
});

test('wasRevokedByGuardianshipRemoval distinguishes a system-triggered lapse from a guardian\'s own revoke', function () {
    $guardianRevoked = VideoConsent::factory()->create([
        'revoked_at' => now(),
        'revocation_reason' => VideoConsentRevocationReasonEnum::guardian_action->value,
    ]);
    $systemLapsed = VideoConsent::factory()->create([
        'revoked_at' => now(),
        'revoked_by_guardian_id' => null,
        'revocation_reason' => VideoConsentRevocationReasonEnum::guardianship_removed->value,
    ]);

    expect($guardianRevoked->wasRevokedByGuardianshipRemoval())->toBeFalse()
        ->and($systemLapsed->wasRevokedByGuardianshipRemoval())->toBeTrue();
});

test('Therapy has a video_consent_mode attribute that defaults to null', function () {
    $therapy = Therapy::factory()->create();

    expect($therapy->video_consent_mode)->toBeNull();
});

test('Therapy video_consent_mode is mass-assignable and persists', function () {
    $therapy = Therapy::factory()->create(['video_consent_mode' => 'PER_SESSION']);

    expect($therapy->fresh()->video_consent_mode)->toBe('PER_SESSION');
});
