<?php

use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Exceptions\VideoConsentRequiredException;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoSession;
use App\Services\AppService;
use Illuminate\Support\Facades\Notification;

// TT-3.1e-g/SCRUM-286: the full regression matrix named in this ticket's own scope, exercised
// END-TO-END through the real HTTP routes wherever a route exists (not just the individual
// sub-tickets' own unit-level Action tests) -- this is the layer where an integration gap between
// two independently-correct sub-tickets would actually surface.

function fakeVideoProviderForRegression(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}
    };
}

function minorTherapyWithGuardianAndSessionForRegression(array $therapyOverrides = []): array
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
    ], $therapyOverrides));
    $session = Session::factory()->create([
        'for_id' => $therapy->id, 'for_type' => Therapy::class,
        'type' => 'ONLINE', 'status' => 'IN_SESSION', 'start_time' => now(),
    ]);

    return compact('minor', 'counsellorUser', 'counsellor', 'guardian', 'therapy', 'session');
}

// 1a. PER_THERAPY mode: a therapy-scoped grant unlocks every session under it, including one
// that didn't exist yet at grant time -- end to end through the controller + real join gate.
test('PER_THERAPY mode: a therapy-scoped grant unlocks every session, including one created after the grant', function () {
    $data = minorTherapyWithGuardianAndSessionForRegression(['video_consent_mode' => 'PER_THERAPY']);

    $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]))
        ->assertSessionHasNoErrors();

    $laterSession = Session::factory()->create([
        'for_id' => $data['therapy']->id, 'for_type' => Therapy::class,
        'type' => 'ONLINE', 'status' => 'IN_SESSION', 'start_time' => now(),
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minor']))
        ->not->toThrow(VideoException::class);
    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($laterSession, $data['minor']))
        ->not->toThrow(VideoException::class);
});

// 1b. PER_SESSION mode: a grant only unlocks its own session, never a sibling -- a fresh
// therapy/grant with no lingering PER_THERAPY grant from any earlier scope (that would otherwise
// still cover every session under it, per the mode's own prospective-only guarantee -- this test
// isolates PER_SESSION's own scoping, not that separate guarantee).
test('PER_SESSION mode: a grant only unlocks its own session, not a sibling session', function () {
    $data = minorTherapyWithGuardianAndSessionForRegression(['video_consent_mode' => 'PER_SESSION']);
    $siblingSession = Session::factory()->create([
        'for_id' => $data['therapy']->id, 'for_type' => Therapy::class,
        'type' => 'ONLINE', 'status' => 'IN_SESSION', 'start_time' => now()->addHour(),
    ]);

    // GetCurrentVideoConsentableForTherapyAction resolves the SOONEST eligible session -- that's
    // $data['session'] (created first), so it's the one this grant covers.
    $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]))
        ->assertSessionHasNoErrors();

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minor']))
        ->not->toThrow(VideoException::class);
    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($siblingSession, $data['minor']))
        ->toThrow(VideoConsentRequiredException::class);
});

// 2. Revoke mid-call -- the active call actually ends, verified via the real HTTP revoke route
// against a real VideoSession, not just VideoConsent.isValid().
test('revoking through the real controller route immediately ends an active call', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRegression());
    $data = minorTherapyWithGuardianAndSessionForRegression(['video_consent_mode' => 'PER_THERAPY']);
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id, 'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class, 'consentable_id' => $data['therapy']->id,
    ]);
    $videoSession = VideoSession::factory()->create(['session_id' => $data['session']->id]);

    $this->actingAs($data['guardian'])
        ->delete(route('therapies.video_consent.revoke', ['therapyId' => $data['therapy']->id]))
        ->assertSessionHasNoErrors();

    expect($videoSession->fresh()->ended_at)->not->toBeNull();
});

// 3. Guardianship deletion mid-session -- through the real HTTP guardianship-deletion route
// (routes/api.php), not just DeleteGuardianshipAction called directly.
test('deleting a guardianship through the real HTTP route lapses consent and ends an active call', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForRegression());
    $data = minorTherapyWithGuardianAndSessionForRegression(['video_consent_mode' => 'PER_THERAPY']);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id, 'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class, 'consentable_id' => $data['therapy']->id,
    ]);
    $videoSession = VideoSession::factory()->create(['session_id' => $data['session']->id]);
    $guardianship = Guardianship::query()->where('guardian_id', $data['guardian']->id)->where('ward_id', $data['minor']->id)->first();

    $this->actingAs($data['guardian'])
        ->delete(route('api.guardianship.delete', ['guardianshipId' => $guardianship->id]))
        ->assertOk();

    expect($consent->fresh()->isValid())->toBeFalse()
        ->and($consent->fresh()->wasRevokedByGuardianshipRemoval())->toBeTrue()
        ->and($videoSession->fresh()->ended_at)->not->toBeNull();
});

// 4. Multiple co-guardians: any one can act, all can see the audit trail -- through the real
// controller routes for both the acting guardian and the observing co-guardian.
test('any one guardian can grant/revoke, and every co-guardian sees the full attributed audit trail', function () {
    $data = minorTherapyWithGuardianAndSessionForRegression(['video_consent_mode' => 'PER_THERAPY']);
    $coGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $coGuardian->id, 'ward_id' => $data['minor']->id]);

    $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]))
        ->assertSessionHasNoErrors();

    $this->actingAs($coGuardian)
        ->delete(route('therapies.video_consent.revoke', ['therapyId' => $data['therapy']->id]))
        ->assertSessionHasNoErrors();

    $auditResponse = $this->actingAs($data['guardian'])
        ->get(route('therapies.video_consent.audit_trail', ['therapyId' => $data['therapy']->id]));

    $auditResponse->assertOk()->assertJsonCount(1, 'data');
    $entry = $auditResponse->json('data.0');
    expect($entry['grantedByName'])->toBe($data['guardian']->name)
        ->and($entry['revokedByName'])->toBe($coGuardian->name)
        ->and($entry['isValid'])->toBeFalse();
});

// 5. Mode-switch prospective-only, proven at the actual join gate through the real controller
// routes -- a grant made under the old mode still unlocks the join after a later mode switch.
test('a grant made under one mode still unlocks joining after the mode later switches, via the real routes', function () {
    $data = minorTherapyWithGuardianAndSessionForRegression(['video_consent_mode' => 'PER_SESSION']);

    $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]))
        ->assertSessionHasNoErrors();

    $this->actingAs($data['counsellorUser'])
        ->patch(route('therapies.video_consent.mode_update', ['therapyId' => $data['therapy']->id]), ['mode' => 'PER_THERAPY'])
        ->assertSessionHasNoErrors();

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minor']))
        ->not->toThrow(VideoException::class);
});

// 6. A minor with NO guardian at all -- never gains video access via this flow, fails closed by
// construction (there is no one who could ever grant on their behalf).
test('a minor client with no guardian at all is permanently blocked from video via this flow', function () {
    $minorWithNoGuardian = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minorWithNoGuardian->id, 'counsellor_id' => $counsellor->id,
        'video_consent_mode' => 'PER_THERAPY',
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id, 'for_type' => Therapy::class,
        'type' => 'ONLINE', 'status' => 'IN_SESSION', 'start_time' => now(),
    ]);

    // No one can even attempt to grant -- an unrelated user (standing in for "nobody is this
    // minor's guardian") is rejected the same as any other non-guardian caller.
    $anyUser = User::factory()->create();
    $this->actingAs($anyUser)
        ->post(route('therapies.video_consent.grant', ['therapyId' => $therapy->id]))
        ->assertSessionHasErrors('alert');

    expect(VideoConsent::query()->where('ward_id', $minorWithNoGuardian->id)->count())->toBe(0);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $minorWithNoGuardian))
        ->toThrow(VideoConsentRequiredException::class);
});

// 7. Reminder suppression once granted -- re-verified here at the boundary between the grant
// controller route and the reminder sweep, not just AppServiceGuardianVideoConsentRemindersTest's
// own direct-Action-call coverage.
test('no reminder fires for a session once consent has been granted through the real controller route', function () {
    Notification::fake();
    $data = minorTherapyWithGuardianAndSessionForRegression(['video_consent_mode' => 'PER_THERAPY']);
    $data['session']->update(['start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90)]);

    $this->actingAs($data['guardian'])
        ->post(route('therapies.video_consent.grant', ['therapyId' => $data['therapy']->id]))
        ->assertSessionHasNoErrors();

    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();

    Notification::assertNothingSent();
});
