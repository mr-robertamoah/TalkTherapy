<?php

use App\Actions\VideoConsent\RevokeVideoConsentAction;
use App\Exceptions\VideoConsentException;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-c/SCRUM-282: any ONE guardian of the ward may revoke, not just the guardian who
// originally granted -- symmetric with GrantVideoConsentAction's own "any one guardian" rule.

function therapyWithMinorAndTwoGuardians(): array
{
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minor->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $grantingGuardian = User::factory()->create();
    $coGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $grantingGuardian->id, 'ward_id' => $minor->id]);
    Guardianship::query()->create(['guardian_id' => $coGuardian->id, 'ward_id' => $minor->id]);
    $consent = VideoConsent::factory()->create([
        'ward_id' => $minor->id,
        'guardian_id' => $grantingGuardian->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $therapy->id,
    ]);

    return compact('minor', 'therapy', 'grantingGuardian', 'coGuardian', 'consent');
}

test('the granting guardian can revoke their own grant', function () {
    $data = therapyWithMinorAndTwoGuardians();

    $result = RevokeVideoConsentAction::new()->execute($data['grantingGuardian'], $data['consent']);

    expect($result->isValid())->toBeFalse()
        ->and($result->revoked_by_guardian_id)->toBe($data['grantingGuardian']->id);
});

test('a co-guardian who did not grant the consent can still revoke it', function () {
    $data = therapyWithMinorAndTwoGuardians();

    $result = RevokeVideoConsentAction::new()->execute($data['coGuardian'], $data['consent']);

    expect($result->isValid())->toBeFalse()
        ->and($result->revoked_by_guardian_id)->toBe($data['coGuardian']->id)
        ->and($result->revocation_reason)->toBe('GUARDIAN_ACTION');
});

test('a non-guardian cannot revoke consent', function () {
    $data = therapyWithMinorAndTwoGuardians();
    $unrelatedUser = User::factory()->create();

    expect(fn () => RevokeVideoConsentAction::new()->execute($unrelatedUser, $data['consent']))
        ->toThrow(VideoConsentException::class, 'You are not a guardian of this client.');

    expect($data['consent']->fresh()->isValid())->toBeTrue();
});

test('revoking an already-revoked grant by an authorized guardian is a no-op, not an error', function () {
    $data = therapyWithMinorAndTwoGuardians();
    RevokeVideoConsentAction::new()->execute($data['grantingGuardian'], $data['consent']);
    $revokedAt = $data['consent']->fresh()->revoked_at;

    $result = RevokeVideoConsentAction::new()->execute($data['coGuardian'], $data['consent']);

    expect($result->revoked_at->timestamp)->toBe($revokedAt->timestamp)
        ->and($result->revoked_by_guardian_id)->toBe($data['grantingGuardian']->id);
});
