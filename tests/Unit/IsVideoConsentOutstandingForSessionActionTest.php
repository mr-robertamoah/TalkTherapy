<?php

use App\Actions\VideoConsent\IsVideoConsentOutstandingForSessionAction;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-e/SCRUM-284: mirrors e-d's own future enforcement -- outstanding means neither a valid
// PER_SESSION grant for this session NOR a valid PER_THERAPY grant for its therapy exists yet,
// regardless of the therapy's CURRENT video_consent_mode.

function minorTherapyWithSessionForReminderCheck(array $sessionOverrides = []): array
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

test('consent is outstanding when the therapy has no video consent mode set yet', function () {
    $data = minorTherapyWithSessionForReminderCheck();

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($data['session']))->toBeTrue();
});

test('consent is not outstanding once a valid PER_THERAPY grant exists', function () {
    $data = minorTherapyWithSessionForReminderCheck();
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($data['session']))->toBeFalse();
});

test('consent is not outstanding once a valid PER_SESSION grant exists for that specific session', function () {
    $data = minorTherapyWithSessionForReminderCheck();
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $data['session']->id,
    ]);

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($data['session']))->toBeFalse();
});

test('a PER_SESSION grant for one session does not satisfy a sibling session under the same therapy', function () {
    $data = minorTherapyWithSessionForReminderCheck();
    $siblingSession = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $data['therapy']->id]);
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $siblingSession->id,
    ]);

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($data['session']))->toBeTrue();
});

test('consent becomes outstanding again after a valid grant is revoked', function () {
    $data = minorTherapyWithSessionForReminderCheck();
    VideoConsent::factory()->revoked()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($data['session']))->toBeTrue();
});

test('a mode switch does not make an old, still-valid grant stop satisfying this check', function () {
    $data = minorTherapyWithSessionForReminderCheck();
    $data['therapy']->update(['video_consent_mode' => 'PER_SESSION']);
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $data['session']->id,
    ]);
    $data['therapy']->update(['video_consent_mode' => 'PER_THERAPY']);

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($data['session']))->toBeFalse();
});

test('an adult client\'s session is never outstanding', function () {
    $adultClient = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $adultClient->id, 'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($session))->toBeFalse();
});

test('a group therapy session is never outstanding', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $session = Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id]);

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($session))->toBeFalse();
});

// TT-4.10b/SCRUM-291: outstanding-ness must follow the stable client_was_minor_at_creation
// snapshot, not a live re-check of the client's (self-editable) dob.

test('a client who edited their dob to look adult AFTER creation still has outstanding consent, per the stable snapshot', function () {
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

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($session))->toBeTrue();
});

test('a client whose live dob still reads as a minor has nothing outstanding once the snapshot says adult', function () {
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

    expect(IsVideoConsentOutstandingForSessionAction::new()->execute($session))->toBeFalse();
});
