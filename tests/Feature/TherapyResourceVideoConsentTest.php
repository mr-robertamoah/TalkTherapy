<?php

use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-f/SCRUM-285: TherapyResource's new `videoConsent` field -- null (the whole section
// doesn't apply) unless the therapy actually has a minor client.

test('videoConsent is null for an adult-client therapy', function () {
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);

    $response = $this->actingAs($client)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page->where('therapy.videoConsent', null));
});

test('videoConsent is present for a minor-client therapy, with viewerIsGuardian false for a non-guardian viewer', function () {
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY',
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.videoConsent.mode', 'PER_THERAPY')
        ->where('therapy.videoConsent.viewerIsGuardian', false)
        ->where('therapy.videoConsent.current', null));
});

// Security-review finding (2026-09-11, HIGH): a `public` Therapy is reachable by any visitor,
// including a guest, before EnsureUserHasAccessToTherapyAction ever checks who the viewer is.
// videoConsentData() had no gate of its own, so it disclosed that a public therapy's client is a
// minor, its consent mode, whether consent is currently valid, and the granting/revoking
// guardian's real name, to a completely unauthenticated/unrelated visitor.
test('videoConsent is null for an unrelated authenticated user, even on a public minor-client therapy', function () {
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY', 'public' => true,
    ]);
    VideoConsent::factory()->create([
        'ward_id' => $minor->id, 'guardian_id' => $guardian->id,
        'consentable_type' => Therapy::class, 'consentable_id' => $therapy->id,
    ]);
    $unrelatedUser = User::factory()->create();

    $response = $this->actingAs($unrelatedUser)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page->where('therapy.videoConsent', null));
});

test('videoConsent is null for a guest, on a public minor-client therapy', function () {
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY', 'public' => true,
    ]);

    $response = $this->get(route('therapies.get', ['therapyId' => $therapy->id]));

    // Not assertInertia()->where(..., null) here -- for an unauthenticated (guest) request
    // specifically, that fluent helper reports a present-but-null property as "does not exist"
    // (verified this is a testing-helper quirk, not a real gap: dumping the raw page payload for
    // this exact request shows `"videoConsent" => null` correctly present under
    // props.therapy.data). Extracting the raw prop directly sidesteps the helper's quirk while
    // still proving the actual property this test cares about.
    $response->assertOk();
    // Not `?? 'MISSING'` -- that operator also treats "key present with a null value" the same
    // as "key absent" (isset() semantics), the exact same quirk this test exists to route around.
    $therapyData = $response->viewData('page')['props']['therapy']['data'];
    expect(array_key_exists('videoConsent', $therapyData))->toBeTrue()
        ->and($therapyData['videoConsent'])->toBeNull();
});

test('videoConsent.viewerIsGuardian is true for the minor\'s own guardian', function () {
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY',
    ]);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $minor->id, 'guardian_id' => $guardian->id,
        'consentable_type' => Therapy::class, 'consentable_id' => $therapy->id,
    ]);

    $response = $this->actingAs($guardian)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.videoConsent.viewerIsGuardian', true)
        ->where('therapy.videoConsent.current.id', $consent->id));
});

// TT-4.10b/SCRUM-291: videoConsent's own "minor client" gate must follow the stable
// client_was_minor_at_creation snapshot, not a live re-check of the client's (self-editable) dob.

test('videoConsent is still present for a client who edited their dob to look adult, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY', 'client_was_minor_at_creation' => true,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page->where('therapy.videoConsent.mode', 'PER_THERAPY'));
});

test('videoConsent is null once the snapshot says adult, even if the client\'s live dob still reads as a minor', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY', 'client_was_minor_at_creation' => false,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page->where('therapy.videoConsent', null));
});
