<?php

use App\Actions\VideoConsent\GetCurrentVideoConsentableForTherapyAction;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-3.1e-f/SCRUM-285: what the therapy page's approve/revoke button should act on right now.

function minorTherapyForConsentableResolution(array $overrides = []): Therapy
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

test('PER_THERAPY mode always resolves to the therapy itself', function () {
    $therapy = minorTherapyForConsentableResolution(['video_consent_mode' => 'PER_THERAPY']);
    Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => 'PENDING']);

    $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

    expect($consentable)->toBeInstanceOf(Therapy::class)
        ->and($consentable->id)->toBe($therapy->id);
});

test('an unset mode also resolves to the therapy itself', function () {
    $therapy = minorTherapyForConsentableResolution();

    $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

    expect($consentable)->toBeInstanceOf(Therapy::class);
});

test('PER_SESSION mode resolves to the soonest pending or in-progress session', function () {
    $therapy = minorTherapyForConsentableResolution(['video_consent_mode' => 'PER_SESSION']);
    $later = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'status' => 'PENDING', 'start_time' => now()->addDays(3),
    ]);
    $sooner = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'status' => 'PENDING', 'start_time' => now()->addHours(2),
    ]);

    $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

    expect($consentable)->toBeInstanceOf(Session::class)
        ->and($consentable->id)->toBe($sooner->id);
});

test('PER_SESSION mode prefers a currently in-progress session even if a pending one starts sooner in sort order', function () {
    $therapy = minorTherapyForConsentableResolution(['video_consent_mode' => 'PER_SESSION']);
    $inProgress = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'status' => 'IN_SESSION', 'start_time' => now()->subMinutes(10),
    ]);
    $pending = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'status' => 'PENDING', 'start_time' => now()->addHours(2),
    ]);

    $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

    expect($consentable->id)->toBe($inProgress->id);
});

test('PER_SESSION mode with no eligible session at all returns null', function () {
    $therapy = minorTherapyForConsentableResolution(['video_consent_mode' => 'PER_SESSION']);
    Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => 'FAILED']);

    $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

    expect($consentable)->toBeNull();
});

test('PER_SESSION mode ignores a session belonging to a different therapy', function () {
    $therapy = minorTherapyForConsentableResolution(['video_consent_mode' => 'PER_SESSION']);
    $otherTherapy = minorTherapyForConsentableResolution(['video_consent_mode' => 'PER_SESSION']);
    Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $otherTherapy->id, 'status' => 'PENDING']);

    $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

    expect($consentable)->toBeNull();
});
