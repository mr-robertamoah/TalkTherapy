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

test('hasGuardian returns true when the user has at least one guardian', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);

    expect($ward->hasGuardian())->toBeTrue();
    expect($guardian->hasGuardian())->toBeFalse();
});
