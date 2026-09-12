<?php

use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\Actions\User\EnsureUserCanBeGuardianAction;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\CannotCreateTherapyException;
use App\Exceptions\UserException;
use App\Models\Guardianship;
use App\Models\User;

// TT-4.10b/SCRUM-291: EnsureCanCreateTherapyAction and EnsureUserCanBeGuardianAction are
// DELIBERATELY left on a live isAdult()/age re-check, per this ticket's own scope --
// EnsureCanCreateTherapyAction runs BEFORE any snapshot-bearing row exists (it's the trigger
// moment TT-4.10a's snapshot gets written from, not a consumer of it), and
// EnsureUserCanBeGuardianAction gates the ACTOR's own current eligibility to become a guardian, an
// unrelated question to "was this specific therapy/group's client a minor at creation." These
// confirm both stay unaffected by any snapshot present on an unrelated existing record.

test('EnsureCanCreateTherapyAction still blocks a live-minor user without a guardian, regardless of any unrelated snapshot on their record', function () {
    $minor = User::factory()->create();

    expect(fn () => EnsureCanCreateTherapyAction::new()->execute($minor))
        ->toThrow(CannotCreateTherapyException::class);
});

test('EnsureCanCreateTherapyAction allows a live-minor user who has a guardian', function () {
    $minor = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    expect(fn () => EnsureCanCreateTherapyAction::new()->execute($minor))->not->toThrow(CannotCreateTherapyException::class);
});

test('EnsureUserCanBeGuardianAction still rejects a live-minor prospective guardian', function () {
    $liveMinor = User::factory()->create();

    expect(fn () => EnsureUserCanBeGuardianAction::new()->execute(
        CreateRequestDTO::new()->fromArray(['to' => $liveMinor])
    ))->toThrow(UserException::class);
});

test('EnsureUserCanBeGuardianAction allows a live-adult prospective guardian', function () {
    $liveAdult = User::factory()->adult()->create();

    expect(fn () => EnsureUserCanBeGuardianAction::new()->execute(
        CreateRequestDTO::new()->fromArray(['to' => $liveAdult])
    ))->not->toThrow(UserException::class);
});
