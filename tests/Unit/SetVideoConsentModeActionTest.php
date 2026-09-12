<?php

use App\Actions\VideoConsent\SetVideoConsentModeAction;
use App\Exceptions\VideoConsentException;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-b/SCRUM-281: settable by either the assigned counsellor or any guardian of the minor
// client -- never the client themselves, never an unrelated user.

function minorTherapyForModeSet(): array
{
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minor->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return compact('minor', 'counsellorUser', 'counsellor', 'therapy');
}

test('the assigned counsellor can set the video consent mode', function () {
    $data = minorTherapyForModeSet();

    $therapy = SetVideoConsentModeAction::new()->execute($data['therapy'], $data['counsellorUser'], 'PER_SESSION');

    expect($therapy->fresh()->video_consent_mode)->toBe('PER_SESSION');
});

test('a guardian of the minor client can set the video consent mode', function () {
    $data = minorTherapyForModeSet();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $data['minor']->id]);

    $therapy = SetVideoConsentModeAction::new()->execute($data['therapy'], $guardian, 'PER_THERAPY');

    expect($therapy->fresh()->video_consent_mode)->toBe('PER_THERAPY');
});

test('the minor client themselves cannot set the video consent mode', function () {
    $data = minorTherapyForModeSet();

    expect(fn () => SetVideoConsentModeAction::new()->execute($data['therapy'], $data['minor'], 'PER_THERAPY'))
        ->toThrow(VideoConsentException::class, 'You are not allowed to set this therapy\'s video consent mode.');

    expect($data['therapy']->fresh()->video_consent_mode)->toBeNull();
});

test('an unrelated user cannot set the video consent mode', function () {
    $data = minorTherapyForModeSet();
    $unrelatedUser = User::factory()->create();

    expect(fn () => SetVideoConsentModeAction::new()->execute($data['therapy'], $unrelatedUser, 'PER_THERAPY'))
        ->toThrow(VideoConsentException::class);
});

test('an unrelated counsellor (not assigned to this therapy) cannot set the mode', function () {
    $data = minorTherapyForModeSet();
    $otherCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $otherCounsellorUser->id]);

    expect(fn () => SetVideoConsentModeAction::new()->execute($data['therapy'], $otherCounsellorUser, 'PER_THERAPY'))
        ->toThrow(VideoConsentException::class);
});

test('setting the mode fails when the therapy has an adult client', function () {
    $adultClient = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $adultClient->id,
        'counsellor_id' => $counsellor->id,
    ]);

    expect(fn () => SetVideoConsentModeAction::new()->execute($therapy, $counsellorUser, 'PER_THERAPY'))
        ->toThrow(VideoConsentException::class, 'Video consent mode only applies to a minor client.');
});

// TT-4.10b/SCRUM-291: the mode-set gate must follow the stable client_was_minor_at_creation
// snapshot, not a live re-check of the client's (self-editable) dob.

test('setting the mode succeeds for a client who edited their dob to look adult, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'client_was_minor_at_creation' => true,
    ]);

    $therapy = SetVideoConsentModeAction::new()->execute($therapy, $counsellorUser, 'PER_THERAPY');

    expect($therapy->fresh()->video_consent_mode)->toBe('PER_THERAPY');
});

test('setting the mode fails once the snapshot says adult, even if the client\'s live dob still reads as a minor', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'client_was_minor_at_creation' => false,
    ]);

    expect(fn () => SetVideoConsentModeAction::new()->execute($therapy, $counsellorUser, 'PER_THERAPY'))
        ->toThrow(VideoConsentException::class, 'Video consent mode only applies to a minor client.');
});

test('an invalid mode value is rejected', function () {
    $data = minorTherapyForModeSet();

    expect(fn () => SetVideoConsentModeAction::new()->execute($data['therapy'], $data['counsellorUser'], 'NOT_A_REAL_MODE'))
        ->toThrow(VideoConsentException::class, 'Invalid video consent mode.');
});

test('switching the mode is prospective-only -- an existing valid grant is untouched', function () {
    $data = minorTherapyForModeSet();
    $data['therapy']->update(['video_consent_mode' => 'PER_THERAPY']);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    SetVideoConsentModeAction::new()->execute($data['therapy'], $data['counsellorUser'], 'PER_SESSION');

    expect($consent->fresh()->isValid())->toBeTrue()
        ->and($data['therapy']->fresh()->video_consent_mode)->toBe('PER_SESSION');
});
