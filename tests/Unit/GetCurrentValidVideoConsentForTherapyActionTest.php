<?php

use App\Actions\VideoConsent\GetCurrentValidVideoConsentForTherapyAction;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-f/SCRUM-285: the currently-valid grant (if any) for whatever
// GetCurrentVideoConsentableForTherapyAction resolves.

function minorTherapyForCurrentConsentLookup(array $overrides = []): Therapy
{
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $minor->id,
        'counsellor_id' => $counsellor->id,
    ], $overrides));
}

test('returns the valid PER_THERAPY grant when one exists', function () {
    $therapy = minorTherapyForCurrentConsentLookup(['video_consent_mode' => 'PER_THERAPY']);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $therapy->addedby->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);

    $result = GetCurrentValidVideoConsentForTherapyAction::new()->execute($therapy);

    expect($result->id)->toBe($consent->id);
});

test('returns null when no grant exists yet', function () {
    $therapy = minorTherapyForCurrentConsentLookup(['video_consent_mode' => 'PER_THERAPY']);

    expect(GetCurrentValidVideoConsentForTherapyAction::new()->execute($therapy))->toBeNull();
});

test('returns null when the only grant has been revoked', function () {
    $therapy = minorTherapyForCurrentConsentLookup(['video_consent_mode' => 'PER_THERAPY']);
    VideoConsent::factory()->revoked()->create([
        'ward_id' => $therapy->addedby->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);

    expect(GetCurrentValidVideoConsentForTherapyAction::new()->execute($therapy))->toBeNull();
});

test('returns the valid grant for the currently-relevant session under PER_SESSION mode', function () {
    $therapy = minorTherapyForCurrentConsentLookup(['video_consent_mode' => 'PER_SESSION']);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => 'PENDING']);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $therapy->addedby->id,
        'consentable_type' => Session::class,
        'consentable_id' => $session->id,
    ]);

    $result = GetCurrentValidVideoConsentForTherapyAction::new()->execute($therapy);

    expect($result->id)->toBe($consent->id);
});

test('returns null under PER_SESSION mode when there is no eligible session at all', function () {
    $therapy = minorTherapyForCurrentConsentLookup(['video_consent_mode' => 'PER_SESSION']);

    expect(GetCurrentValidVideoConsentForTherapyAction::new()->execute($therapy))->toBeNull();
});
