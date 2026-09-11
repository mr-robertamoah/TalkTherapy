<?php

use App\Actions\VideoConsent\InvalidateVideoConsentAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\SessionStatusEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoSession;

// TT-3.1e-c/SCRUM-282: the shared invalidation path -- revokes the row and, synchronously
// (not a lazily-checked flag), ends any currently-active video call under the revoked scope.

function therapyWithMinorAndGuardianForInvalidation(array $overrides = []): array
{
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $minor->id,
        'counsellor_id' => $counsellor->id,
    ], $overrides));
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    return compact('minor', 'counsellorUser', 'counsellor', 'therapy', 'guardian');
}

function fakeVideoProviderForInvalidation(): VideoProviderInterface
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

test('revoking sets revoked_at, revoked_by_guardian_id, and revocation_reason for a guardian action', function () {
    $data = therapyWithMinorAndGuardianForInvalidation();
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    $result = InvalidateVideoConsentAction::new()->execute($consent, VideoConsentRevocationReasonEnum::guardian_action, $data['guardian']);

    expect($result->isValid())->toBeFalse()
        ->and($result->revoked_at)->not->toBeNull()
        ->and($result->revoked_by_guardian_id)->toBe($data['guardian']->id)
        ->and($result->revocation_reason)->toBe('GUARDIAN_ACTION');
});

test('a guardianship-removal invalidation leaves revoked_by_guardian_id null', function () {
    $data = therapyWithMinorAndGuardianForInvalidation();
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    $result = InvalidateVideoConsentAction::new()->execute($consent, VideoConsentRevocationReasonEnum::guardianship_removed);

    expect($result->isValid())->toBeFalse()
        ->and($result->revoked_by_guardian_id)->toBeNull()
        ->and($result->revocation_reason)->toBe('GUARDIANSHIP_REMOVED')
        ->and($result->wasRevokedByGuardianshipRemoval())->toBeTrue();
});

test('revoking a PER_THERAPY consent immediately ends every in-progress session under that therapy', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForInvalidation());
    $data = therapyWithMinorAndGuardianForInvalidation();
    $inSession = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $data['therapy']->id,
        'status' => SessionStatusEnum::in_session->value,
    ]);
    $inSessionVideo = VideoSession::factory()->create(['session_id' => $inSession->id]);
    $inSessionConfirmation = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $data['therapy']->id,
        'status' => SessionStatusEnum::in_session_confirmation->value,
    ]);
    $inSessionConfirmationVideo = VideoSession::factory()->create(['session_id' => $inSessionConfirmation->id]);
    $pendingSession = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $data['therapy']->id,
        'status' => SessionStatusEnum::pending->value,
    ]);
    $pendingVideo = VideoSession::factory()->create(['session_id' => $pendingSession->id]);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    InvalidateVideoConsentAction::new()->execute($consent, VideoConsentRevocationReasonEnum::guardian_action, $data['guardian']);

    expect($inSessionVideo->fresh()->ended_at)->not->toBeNull()
        ->and($inSessionConfirmationVideo->fresh()->ended_at)->not->toBeNull()
        ->and($pendingVideo->fresh()->ended_at)->toBeNull();
});

test('revoking a PER_SESSION consent ends only that session\'s active call, not a sibling session', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForInvalidation());
    $data = therapyWithMinorAndGuardianForInvalidation();
    $targetSession = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $data['therapy']->id,
        'status' => SessionStatusEnum::in_session->value,
    ]);
    $targetVideo = VideoSession::factory()->create(['session_id' => $targetSession->id]);
    $siblingSession = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $data['therapy']->id,
        'status' => SessionStatusEnum::in_session->value,
    ]);
    $siblingVideo = VideoSession::factory()->create(['session_id' => $siblingSession->id]);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $targetSession->id,
    ]);

    InvalidateVideoConsentAction::new()->execute($consent, VideoConsentRevocationReasonEnum::guardian_action, $data['guardian']);

    expect($targetVideo->fresh()->ended_at)->not->toBeNull()
        ->and($siblingVideo->fresh()->ended_at)->toBeNull();
});

test('revoking a PER_SESSION consent still ends the call when the session has since been soft-deleted', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForInvalidation());
    $data = therapyWithMinorAndGuardianForInvalidation();
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $data['therapy']->id,
        'status' => SessionStatusEnum::in_session->value,
    ]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id]);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $session->id,
    ]);
    $session->delete();

    InvalidateVideoConsentAction::new()->execute($consent, VideoConsentRevocationReasonEnum::guardian_action, $data['guardian']);

    expect($videoSession->fresh()->ended_at)->not->toBeNull();
});

test('revoking a PER_THERAPY consent still ends a soft-deleted session\'s call, not just live ones', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForInvalidation());
    $data = therapyWithMinorAndGuardianForInvalidation();
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $data['therapy']->id,
        'status' => SessionStatusEnum::in_session->value,
    ]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id]);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);
    $session->delete();

    InvalidateVideoConsentAction::new()->execute($consent, VideoConsentRevocationReasonEnum::guardian_action, $data['guardian']);

    expect($videoSession->fresh()->ended_at)->not->toBeNull();
});

test('revoking an already-revoked consent is a no-op', function () {
    $data = therapyWithMinorAndGuardianForInvalidation();
    $consent = VideoConsent::factory()->revoked()->create([
        'ward_id' => $data['minor']->id,
        'guardian_id' => $data['guardian']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);
    $originalRevokedAt = $consent->revoked_at;

    $result = InvalidateVideoConsentAction::new()->execute($consent, VideoConsentRevocationReasonEnum::guardian_action, $data['guardian']);

    expect($result->revoked_at->timestamp)->toBe($originalRevokedAt->timestamp)
        ->and($result->revocation_reason)->toBeNull();
});
