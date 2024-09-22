<?php

use App\Providers\RouteServiceProvider;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/preferences');

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'username' => 'testuser',
        'email' => 'test@example.com',
    ]);
});
