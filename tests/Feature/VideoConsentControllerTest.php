<?php

use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-f/SCRUM-285: the first HTTP-reachable surface for the guardian video-consent Actions.

function minorTherapyWithGuardianForController(array $overrides = []): array
{
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $therapy = Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $minor->id,
        'counsellor_id' => $counsellor->id,
    ], $overrides));

    return compact('minor', 'counsellorUser', 'guardian', 'therapy');
}

test('the assigned counsellor can update the video consent mode', function () {
    $data = minorTherapyWithGuardianForController();

    $response = $this->actingAs($data['counsellorUser'])
        ->patch(route('therapies.video_consent.mode_update', ['therapyId' => $data['therapy']->id]), ['mode' => 'PER_THERAPY']);

    $response->assertSessionHasNoErrors();
    expect($data['therapy']->fresh()->video_consent_mode)->toBe('PER_THERAPY');
});

test('a guardian can update the video consent mode', function () {
    $data = minorTherapyWithGuardianForController();

    $response = $this->actingAs($data['guardian'])
        ->patch(route('therapies.video_consent.mode_update', ['therapyId' => $data['therapy']->id]), ['mode' => 'PER_SESSION']);

    $response->assertSessionHasNoErrors();
    expect($data['therapy']->fresh()->video_consent_mode)->toBe('PER_SESSION');
});

test('an unrelated user cannot update the video consent mode', function () {
    $data = minorTherapyWithGuardianForController();
    $unrelatedUser = User::factory()->create();

    $response = $this->actingAs($unrelatedUser)
        ->patch(route('therapies.video_consent.mode_update', ['therapyId' => $data['therapy']->id]), ['mode' => 'PER_THERAPY']);

    $response->assertSessionHasErrors('alert');
    expect($data['therapy']->fresh()->video_consent_mode)->toBeNull();
});

test('a guardian can grant PER_THERAPY consent through the controller', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);

    $response = $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]));

    $response->assertSessionHasNoErrors();
    expect(VideoConsent::query()->where('consentable_type', Therapy::class)->where('consentable_id', $data['therapy']->id)->exists())->toBeTrue();
});

test('a guardian can grant PER_SESSION consent through the controller for the soonest eligible session', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_SESSION']);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $data['therapy']->id, 'status' => 'PENDING']);

    $response = $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]));

    $response->assertSessionHasNoErrors();
    expect(VideoConsent::query()->where('consentable_type', Session::class)->where('consentable_id', $session->id)->exists())->toBeTrue();
});

test('granting under PER_SESSION mode with no eligible session gives a friendly error, not a crash', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_SESSION']);

    $response = $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]));

    $response->assertSessionHasErrors('alert');
});

test('a non-guardian cannot grant consent through the controller', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);
    $unrelatedUser = User::factory()->create();

    $response = $this->actingAs($unrelatedUser)
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]));

    $response->assertSessionHasErrors('alert');
    expect(VideoConsent::query()->count())->toBe(0);
});

test('a guardian can revoke the current valid consent through the controller', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    $response = $this->actingAs($data['guardian'])
        ->delete(route('therapies.video_consent.revoke', ['therapyId' => $data['therapy']->id]));

    $response->assertSessionHasNoErrors();
    expect($consent->fresh()->isValid())->toBeFalse();
});

test('revoking with no active consent gives a friendly error, not a crash', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);

    $response = $this->actingAs($data['guardian'])
        ->delete(route('therapies.video_consent.revoke', ['therapyId' => $data['therapy']->id]));

    $response->assertSessionHasErrors('alert');
});

test('a non-guardian cannot revoke consent through the controller', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);
    $unrelatedUser = User::factory()->create();

    $response = $this->actingAs($unrelatedUser)
        ->delete(route('therapies.video_consent.revoke', ['therapyId' => $data['therapy']->id]));

    $response->assertSessionHasErrors('alert');
    expect($consent->fresh()->isValid())->toBeTrue();
});

test('a guardian can read the audit trail via JSON', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    $response = $this->actingAs($data['guardian'])
        ->get(route('therapies.video_consent.audit_trail', ['therapyId' => $data['therapy']->id]));

    $response->assertOk()->assertJsonCount(1, 'data');
});

test('a non-guardian is refused the audit trail', function () {
    $data = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);
    $unrelatedUser = User::factory()->create();

    $response = $this->actingAs($unrelatedUser)
        ->get(route('therapies.video_consent.audit_trail', ['therapyId' => $data['therapy']->id]));

    $response->assertStatus(422);
});

// Security-review finding (2026-09-11): each endpoint used to delegate straight to its
// underlying Action, whose OWN denial message differs by the therapy's actual state ("no minor
// client" vs "only applies to a minor client" vs "not a guardian") -- letting an unrelated
// stranger learn whether an arbitrary therapy's client is a minor purely from which message came
// back. All four endpoints must now return the exact same generic denial for an unrelated caller,
// regardless of whether the target therapy has an adult client, a minor client, or no resolvable
// User client at all.
test('an unrelated user gets the identical generic denial regardless of the target therapy\'s actual minor/adult state', function () {
    $unrelatedUser = User::factory()->create();

    $minorData = minorTherapyWithGuardianForController(['video_consent_mode' => 'PER_THERAPY']);
    $adultClient = User::factory()->adult()->create();
    $adultTherapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $adultClient->id]);

    $genericMessage = 'You are not allowed to access this therapy\'s video consent settings.';

    foreach ([$minorData['therapy'], $adultTherapy] as $therapy) {
        $modeResponse = $this->actingAs($unrelatedUser)
            ->patch(route('therapies.video_consent.mode_update', ['therapyId' => $therapy->id]), ['mode' => 'PER_THERAPY']);
        $modeResponse->assertSessionHasErrors(['alert' => $genericMessage]);

        $grantResponse = $this->actingAs($unrelatedUser)
            ->post(route('therapies.video_consent.grant', ['therapyId' => $therapy->id]));
        $grantResponse->assertSessionHasErrors(['alert' => $genericMessage]);

        $revokeResponse = $this->actingAs($unrelatedUser)
            ->delete(route('therapies.video_consent.revoke', ['therapyId' => $therapy->id]));
        $revokeResponse->assertSessionHasErrors(['alert' => $genericMessage]);

        $auditResponse = $this->actingAs($unrelatedUser)
            ->get(route('therapies.video_consent.audit_trail', ['therapyId' => $therapy->id]));
        $auditResponse->assertStatus(422)->assertJson(['message' => $genericMessage]);
    }
});
