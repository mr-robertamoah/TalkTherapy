<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'quiet-harbour-2026',
        'password_confirmation' => 'quiet-harbour-2026',
    ]);

    $response->assertRedirect('/preferences');

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'username' => 'testuser',
        'email' => 'test@example.com',
    ]);
});
