<?php

use App\Models\User;
use App\Providers\RouteServiceProvider;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(RouteServiceProvider::HOME);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

// The lockout is per account and address: it used to read only `email`, so every username sign-in from one address
// shared a counter, and five wrong passwords for one person locked out everyone signing in from there.
test('five wrong passwords lock that username out, not everyone else on the same address', function () {
    $locked = User::factory()->create();
    $other = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['username' => $locked->username, 'password' => 'wrong-password']);
    }

    $this->post('/login', ['username' => $locked->username, 'password' => 'password'])
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    $this->post('/login', ['username' => $other->username, 'password' => 'password']);
    $this->assertAuthenticatedAs($other);
});

test('the same username in different letter cases shares one lockout', function () {
    $user = User::factory()->create(['username' => 'casey_lock']);

    foreach (['casey_lock', 'CASEY_LOCK', 'Casey_Lock', 'casey_LOCK', 'CaSeY_lOcK'] as $variant) {
        $this->post('/login', ['username' => $variant, 'password' => 'wrong-password']);
    }

    $this->post('/login', ['username' => 'casey_lock', 'password' => 'password'])->assertSessionHasErrors('username');
    $this->assertGuest();
});

test('an email sign-in is still locked out after five wrong passwords', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});
