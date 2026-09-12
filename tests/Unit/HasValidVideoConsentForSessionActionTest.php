<?php

use App\Actions\VideoConsent\HasValidVideoConsentForSessionAction;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-d/SCRUM-283: the hot-path, single-query enforcement check -- an OR across both scope
// types, regardless of the therapy's current video_consent_mode.

function minorTherapyWithSessionForEnforcement(array $sessionOverrides = []): array
{
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minor->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(array_merge([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ], $sessionOverrides));

    return compact('minor', 'therapy', 'session');
}

test('no consent grant means the check fails', function () {
    $data = minorTherapyWithSessionForEnforcement();

    expect(HasValidVideoConsentForSessionAction::new()->execute($data['session']))->toBeFalse();
});

test('a valid PER_THERAPY grant satisfies the check', function () {
    $data = minorTherapyWithSessionForEnforcement();
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($data['session']))->toBeTrue();
});

test('a valid PER_SESSION grant for this exact session satisfies the check', function () {
    $data = minorTherapyWithSessionForEnforcement();
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $data['session']->id,
    ]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($data['session']))->toBeTrue();
});

test('a PER_SESSION grant for a different session does not satisfy this session\'s check', function () {
    $data = minorTherapyWithSessionForEnforcement();
    $otherSession = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $data['therapy']->id]);
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $otherSession->id,
    ]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($data['session']))->toBeFalse();
});

test('a revoked grant no longer satisfies the check', function () {
    $data = minorTherapyWithSessionForEnforcement();
    VideoConsent::factory()->revoked()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($data['session']))->toBeFalse();
});

test('a grant belonging to a different ward does not satisfy this ward\'s check', function () {
    $data = minorTherapyWithSessionForEnforcement();
    $otherWard = User::factory()->create();
    VideoConsent::factory()->create([
        'ward_id' => $otherWard->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($data['session']))->toBeFalse();
});

test('an adult client\'s session always satisfies the check (nothing to gate on)', function () {
    $adultClient = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $adultClient->id, 'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($session))->toBeTrue();
});

test('a group therapy session always satisfies the check (no ward concept)', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $session = Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($session))->toBeTrue();
});

// TT-4.10b/SCRUM-291: the whole point of client_was_minor_at_creation is that it must NOT be
// re-derivable from the client's current, possibly-edited dob -- these two prove the snapshot
// wins over a live isAdult() re-check in both directions.

test('a client who edited their dob to look adult AFTER creation still gets gated, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'client_was_minor_at_creation' => true,
    ]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($session))->toBeFalse();
});

test('a client whose live dob still reads as a minor is NOT gated once the snapshot says adult', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'client_was_minor_at_creation' => false,
    ]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    expect(HasValidVideoConsentForSessionAction::new()->execute($session))->toBeTrue();
});
