<?php

use App\Models\Guardianship;
use App\Models\User;

test('isGuardianOf returns true when the given user is a real ward', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);

    expect($guardian->isGuardianOf($ward))->toBeTrue();
});

test('isGuardianOf returns false for two unrelated users', function () {
    $guardian = User::factory()->create();
    $unrelatedUser = User::factory()->create();

    expect($guardian->isGuardianOf($unrelatedUser))->toBeFalse();
});

test('isGuardianOf returns false when the relationship is reversed', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);

    expect($ward->isGuardianOf($guardian))->toBeFalse();
});

test('hasActiveGuardian is true when the user has an adult guardian, and not for the guardian', function () {
    $guardian = User::factory()->adult()->create();
    $ward = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);

    expect($ward->hasActiveGuardian())->toBeTrue();
    expect($guardian->hasActiveGuardian())->toBeFalse();
});

// SCRUM-288: a guardian counts only while their account exists, is adult and isn't suspended.
test('a guardian with no date of birth, or under 18, does not count', function () {
    $ward = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => User::factory()->create()->id, 'ward_id' => $ward->id]);
    Guardianship::query()->create(['guardian_id' => User::factory()->create(['dob' => now()->subYears(17)->toDateString()])->id, 'ward_id' => $ward->id]);

    expect($ward->hasActiveGuardian())->toBeFalse();

    Guardianship::query()->create(['guardian_id' => User::factory()->create(['dob' => now()->subYears(18)->toDateString()])->id, 'ward_id' => $ward->id]);

    expect($ward->hasActiveGuardian(fresh: true))->toBeTrue();
});
