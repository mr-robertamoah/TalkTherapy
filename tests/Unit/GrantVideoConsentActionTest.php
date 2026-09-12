<?php

use App\Actions\VideoConsent\GrantVideoConsentAction;
use App\Exceptions\VideoConsentException;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-b/SCRUM-281: any ONE guardian of the ward may grant, idempotently and race-safely, only
// for the scope type matching the therapy's currently-set video_consent_mode.

function minorTherapyForConsentGrant(array $overrides = []): Therapy
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

test('a guardian can grant PER_THERAPY consent for the ward\'s therapy', function () {
    $therapy = minorTherapyForConsentGrant(['video_consent_mode' => 'PER_THERAPY']);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapy->addedby->id]);

    $consent = GrantVideoConsentAction::new()->execute($guardian, $therapy);

    expect($consent)->toBeInstanceOf(VideoConsent::class)
        ->and($consent->ward_id)->toBe($therapy->addedby->id)
        ->and($consent->guardian_id)->toBe($guardian->id)
        ->and($consent->consentable_type)->toBe(Therapy::class)
        ->and($consent->consentable_id)->toBe($therapy->id)
        ->and($consent->isValid())->toBeTrue();
});

test('a guardian can grant PER_SESSION consent for a specific session', function () {
    $therapy = minorTherapyForConsentGrant(['video_consent_mode' => 'PER_SESSION']);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapy->addedby->id]);

    $consent = GrantVideoConsentAction::new()->execute($guardian, $session);

    expect($consent->consentable_type)->toBe(Session::class)
        ->and($consent->consentable_id)->toBe($session->id);
});

test('granting fails when the therapy has no video consent mode set yet', function () {
    $therapy = minorTherapyForConsentGrant();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapy->addedby->id]);

    expect(fn () => GrantVideoConsentAction::new()->execute($guardian, $therapy))
        ->toThrow(VideoConsentException::class, 'This therapy has no video consent mode set yet.');

    expect(VideoConsent::query()->count())->toBe(0);
});

test('granting a PER_THERAPY scope while the mode is PER_SESSION is rejected', function () {
    $therapy = minorTherapyForConsentGrant(['video_consent_mode' => 'PER_SESSION']);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapy->addedby->id]);

    expect(fn () => GrantVideoConsentAction::new()->execute($guardian, $therapy))
        ->toThrow(VideoConsentException::class, 'This consent scope does not match the therapy\'s current video consent mode.');
});

test('granting a PER_SESSION scope while the mode is PER_THERAPY is rejected', function () {
    $therapy = minorTherapyForConsentGrant(['video_consent_mode' => 'PER_THERAPY']);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapy->addedby->id]);

    expect(fn () => GrantVideoConsentAction::new()->execute($guardian, $session))
        ->toThrow(VideoConsentException::class);
});

test('a non-guardian cannot grant consent', function () {
    $therapy = minorTherapyForConsentGrant(['video_consent_mode' => 'PER_THERAPY']);
    $unrelatedUser = User::factory()->create();

    expect(fn () => GrantVideoConsentAction::new()->execute($unrelatedUser, $therapy))
        ->toThrow(VideoConsentException::class, 'You are not a guardian of this client.');

    expect(VideoConsent::query()->count())->toBe(0);
});

test('granting fails when the therapy\'s client is an adult', function () {
    $adultClient = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $adultClient->id,
        'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY',
    ]);
    $guardian = User::factory()->create();

    expect(fn () => GrantVideoConsentAction::new()->execute($guardian, $therapy))
        ->toThrow(VideoConsentException::class, 'Video consent only applies to a minor client.');
});

// TT-4.10b/SCRUM-291: the "minor client" gate must follow the stable client_was_minor_at_creation
// snapshot, not a live re-check of the client's (self-editable) dob.

test('granting succeeds for a client who edited their dob to look adult, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY',
        'client_was_minor_at_creation' => true,
    ]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);

    $consent = GrantVideoConsentAction::new()->execute($guardian, $therapy);

    expect($consent)->toBeInstanceOf(VideoConsent::class);
});

test('granting fails once the snapshot says adult, even if the client\'s live dob still reads as a minor', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY',
        'client_was_minor_at_creation' => false,
    ]);
    $guardian = User::factory()->create();

    expect(fn () => GrantVideoConsentAction::new()->execute($guardian, $therapy))
        ->toThrow(VideoConsentException::class, 'Video consent only applies to a minor client.');
});

test('granting is idempotent -- calling it again for an already-valid scope returns the same row', function () {
    $therapy = minorTherapyForConsentGrant(['video_consent_mode' => 'PER_THERAPY']);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapy->addedby->id]);

    $first = GrantVideoConsentAction::new()->execute($guardian, $therapy);
    $second = GrantVideoConsentAction::new()->execute($guardian, $therapy);

    expect($second->id)->toBe($first->id)
        ->and(VideoConsent::query()->count())->toBe(1);
});

test('granting after a prior revocation creates a fresh row, not reusing the revoked one', function () {
    $therapy = minorTherapyForConsentGrant(['video_consent_mode' => 'PER_THERAPY']);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapy->addedby->id]);
    $revoked = VideoConsent::factory()->revoked()->create([
        'ward_id' => $therapy->addedby->id,
        'guardian_id' => $guardian->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);

    $reGranted = GrantVideoConsentAction::new()->execute($guardian, $therapy);

    expect($reGranted->id)->not->toBe($revoked->id)
        ->and($reGranted->isValid())->toBeTrue();
});
