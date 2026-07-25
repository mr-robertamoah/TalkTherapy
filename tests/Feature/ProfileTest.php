<?php

use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => 'test@example.com',
        ]);

    $user->refresh();

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNotNull($user->fresh());
    $this->assertNotNull($user->deleted_at);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

test('deleting a counsellor account soft-deletes the linked counsellor too', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create([
        'user_id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNotNull($user->fresh()->deleted_at);
    $this->assertNotNull($counsellor->fresh()->deleted_at);
});

test('a counsellor with pending sessions cannot delete their account', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create([
        'user_id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'status' => SessionStatusEnum::pending->value,
    ]);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNull($user->fresh()->deleted_at);
    $this->assertNull($counsellor->fresh()->deleted_at);
});

test('a therapy assigned to a since-deleted counsellor still renders without error', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create([
        'user_id' => $counsellorUser->id,
        'name' => $counsellorUser->name,
        'email' => $counsellorUser->email,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'public' => true,
    ]);

    $this->actingAs($counsellorUser)
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertNotNull($counsellor->fresh()->deleted_at);

    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->getJson('/api/therapies/random?page=1')
        ->assertOk();
});
