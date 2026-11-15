<?php

use App\Models\User;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
});

test('password can be confirmed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors();
});

// SCRUM-435: checked against the signed-in account, not looked up by email.
test('an account with no email can confirm its password, and a wrong one still fails', function () {
    $user = User::factory()->create(['email' => null, 'email_verified_at' => null]);

    $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');

    $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password'])->assertSessionHasErrors('password');
});
