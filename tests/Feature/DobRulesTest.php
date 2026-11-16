<?php

use App\Models\Administrator;
use App\Models\User;

// SCRUM-454: the shared date-of-birth rules (DobRules) on the profile and the admin edit -- a value that isn't a date
// is a validation error (it used to be a 500), and a date in the future is refused.

test('the profile refuses a date of birth that is not a date, or is in the future', function (mixed $dob) {
    $user = User::factory()->adult()->create();
    $before = $user->dob;

    $this->actingAs($user)->patch(route('profile.update'), ['dob' => $dob])->assertSessionHasErrors('dob');

    expect($user->fresh()->dob->toDateString())->toBe($before->toDateString());
})->with([
    'not a date' => 'yesterday-ish',
    'in the future' => fn () => now()->addYears(20)->toDateString(),
]);

test('the admin edit refuses a date of birth that is not a date, or is in the future', function (mixed $dob) {
    $admin = User::factory()->has(Administrator::factory())->create();
    $target = User::factory()->adult()->create();

    $this->actingAs($admin)->postJson(route('admin.users.update', ['userId' => $target->id]), ['firstName' => $target->firstName, 'dob' => $dob])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dob');
})->with([
    'not a date' => 'yesterday-ish',
    'in the future' => fn () => now()->addYears(20)->toDateString(),
]);
