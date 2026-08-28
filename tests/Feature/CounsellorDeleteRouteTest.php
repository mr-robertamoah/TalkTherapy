<?php

use App\Models\Counsellor;
use App\Models\User;

// SCRUM-134: the counsellor.delete route was entirely missing -- the frontend's delete button has
// always called it, it just never existed. Covers the route itself, the current_password
// requirement (mirroring ProfileController::destroy), and cross-user/unauthenticated rejection.

test('an unauthenticated request is redirected to login', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $this->delete(route('counsellor.delete', ['counsellorId' => $counsellor->id]))
        ->assertRedirect(route('login'));
});

test('the owner can delete their counsellor account with the correct password', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('counsellor.delete', ['counsellorId' => $counsellor->id]), ['password' => 'password'])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasNoErrors();

    expect($counsellor->fresh()->deleted_at)->not->toBeNull();
});

test('deletion is rejected without the correct password', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('counsellor.delete', ['counsellorId' => $counsellor->id]), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect($counsellor->fresh()->deleted_at)->toBeNull();
});

test('a user cannot delete someone else\'s counsellor account by spoofing the route parameter', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->delete(route('counsellor.delete', ['counsellorId' => $counsellor->id]), ['password' => 'password'])
        ->assertSessionHasErrors('alert');

    expect($counsellor->fresh()->deleted_at)->toBeNull();
});
