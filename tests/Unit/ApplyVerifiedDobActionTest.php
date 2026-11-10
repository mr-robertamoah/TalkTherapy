<?php

use App\Actions\User\ApplyVerifiedDobAction;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;

// TT-4.11c/SCRUM-304: extracted from RespondToDobChangeRequestAction so both it and
// RespondToAgeVerificationRequestAction share exactly one implementation of "what does it mean
// to correct a person's historical minor status."

test('sets dob and marks it verified when $verified is true', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $newDob = now()->subYears(30)->toDateString();

    ApplyVerifiedDobAction::new()->execute($user, $newDob, verified: true);

    $user = $user->fresh();
    expect($user->dob->toDateString())->toBe($newDob);
    expect($user->dob_verified_at)->not->toBeNull();
});

test('sets dob but leaves it unverified by default', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $newDob = now()->subYears(30)->toDateString();

    ApplyVerifiedDobAction::new()->execute($user, $newDob);

    $user = $user->fresh();
    expect($user->dob->toDateString())->toBe($newDob);
    expect($user->dob_verified_at)->toBeNull();
});

test('an unverified call clears a prior verification', function () {
    $user = User::factory()->create(['dob' => now()->subYears(30)->toDateString(), 'dob_verified_at' => now()]);
    $newDob = now()->subYears(25)->toDateString();

    ApplyVerifiedDobAction::new()->execute($user, $newDob, verified: false);

    expect($user->fresh()->dob_verified_at)->toBeNull();
});

test('retroactively corrects every qualifying minor-status snapshot', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    $guardianship = Guardianship::query()->create([
        'guardian_id' => $guardian->id, 'ward_id' => $user->id, 'ward_was_minor_at_creation' => true,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $user->id, 'client_was_minor_at_creation' => true,
    ]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $user->id, 'client_was_minor_at_creation' => true,
    ]);

    ApplyVerifiedDobAction::new()->execute($user, now()->subYears(30)->toDateString(), verified: true);

    expect($guardianship->fresh()->ward_was_minor_at_creation)->toBeFalse();
    expect($therapy->fresh()->client_was_minor_at_creation)->toBeFalse();
    expect($groupTherapy->fresh()->client_was_minor_at_creation)->toBeFalse();
});
