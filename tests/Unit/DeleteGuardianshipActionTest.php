<?php

use App\Actions\User\DeleteGuardianshipAction;
use App\Contracts\VideoProviderInterface;
use App\DTOs\GetGuardianshipDTO;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoSession;
use App\Notifications\GuardianshipRemovedNotification;
use Illuminate\Support\Facades\Notification;

// TT-3.1e-c/SCRUM-282: extends this pre-existing action with the guardianship-deletion video
// consent cascade -- scoped to ONLY the deleted guardianship's own guardian/ward pair, and only
// this guardian's still-valid grants, per the ticket's explicit "does not touch the ward's
// broader therapy access" boundary.

function fakeVideoProviderForGuardianshipDeletion(): VideoProviderInterface
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

test('deleting a guardianship removes the row and notifies the ward, unchanged pre-existing behaviour', function () {
    Notification::fake();
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    $guardianship = Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = $guardianship;

    DeleteGuardianshipAction::new()->execute($dto);

    expect(Guardianship::query()->find($guardianship->id))->toBeNull();
    Notification::assertSentTo($ward, GuardianshipRemovedNotification::class);
});

test('deleting a guardianship revokes that guardian\'s own valid video consent grant for the ward', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    $guardianship = Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    $consent = VideoConsent::factory()->create(['ward_id' => $ward->id, 'guardian_id' => $guardian->id]);
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = $guardianship;

    DeleteGuardianshipAction::new()->execute($dto);

    expect($consent->fresh()->isValid())->toBeFalse()
        ->and($consent->fresh()->revocation_reason)->toBe('GUARDIANSHIP_REMOVED')
        ->and($consent->fresh()->revoked_by_guardian_id)->toBeNull();
});

test('deleting a guardianship does not touch a co-guardian\'s own valid grant for the same ward', function () {
    $removedGuardian = User::factory()->create();
    $coGuardian = User::factory()->create();
    $ward = User::factory()->create();
    $guardianship = Guardianship::query()->create(['guardian_id' => $removedGuardian->id, 'ward_id' => $ward->id]);
    Guardianship::query()->create(['guardian_id' => $coGuardian->id, 'ward_id' => $ward->id]);
    $coGuardianConsent = VideoConsent::factory()->create(['ward_id' => $ward->id, 'guardian_id' => $coGuardian->id]);
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = $guardianship;

    DeleteGuardianshipAction::new()->execute($dto);

    expect($coGuardianConsent->fresh()->isValid())->toBeTrue();
});

test('deleting a guardianship for one ward does not touch the same guardian\'s valid grant for a different ward', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    $otherWard = User::factory()->create();
    $guardianship = Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $otherWard->id]);
    $otherWardConsent = VideoConsent::factory()->create(['ward_id' => $otherWard->id, 'guardian_id' => $guardian->id]);
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = $guardianship;

    DeleteGuardianshipAction::new()->execute($dto);

    expect($otherWardConsent->fresh()->isValid())->toBeTrue();
});

test('deleting a guardianship immediately ends an active video call the removed guardian\'s grant was covering', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForGuardianshipDeletion());
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $ward->id, 'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'status' => SessionStatusEnum::in_session->value,
    ]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id]);
    $guardianship = Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    VideoConsent::factory()->create([
        'ward_id' => $ward->id, 'guardian_id' => $guardian->id,
        'consentable_type' => Therapy::class, 'consentable_id' => $therapy->id,
    ]);
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = $guardianship;

    DeleteGuardianshipAction::new()->execute($dto);

    expect($videoSession->fresh()->ended_at)->not->toBeNull();
});

test('deleting a guardianship with no video consent grants at all does not error', function () {
    Notification::fake();
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    $guardianship = Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    $dto = new GetGuardianshipDTO;
    $dto->guardianship = $guardianship;

    DeleteGuardianshipAction::new()->execute($dto);

    expect(Guardianship::query()->find($guardianship->id))->toBeNull();
});
