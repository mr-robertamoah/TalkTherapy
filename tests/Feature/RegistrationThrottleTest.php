<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\User;
use App\Support\ClientAddress;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-400: accounts created from one address are limited per hour; failed attempts don't count.
// Helpers are file-local and rgt-prefixed (--parallel).

function rgtRegister($test, string $username, string $ip = '203.0.113.7')
{
    auth()->guard('web')->logout();

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('register'), [
        'username' => $username, 'password' => 'a-Long-password-1', 'password_confirmation' => 'a-Long-password-1',
    ]);
}

beforeEach(function () {
    RateLimiter::clear('registrations:'.ClientAddress::key('203.0.113.7'));
    RateLimiter::clear('registrations:'.ClientAddress::key('198.51.100.9'));
    RateLimiter::clear('registrations:'.ClientAddress::key('2001:db8:1:2::5'));
});

test('one address may create the configured number of accounts an hour, then is refused with a clear message', function () {
    config(['user.registrations_per_hour_per_address' => 2]);
    Log::spy();

    rgtRegister($this, 'rgt_first')->assertRedirect('/preferences');
    rgtRegister($this, 'rgt_second')->assertRedirect('/preferences');
    rgtRegister($this, 'rgt_third')->assertSessionHasErrors(['username' => RegisteredUserController::TOO_MANY]);

    expect(User::query()->where('username', 'like', 'rgt_%')->count())->toBe(2);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Registration refused'))->once();

    // Another address isn't affected.
    rgtRegister($this, 'rgt_elsewhere', '198.51.100.9')->assertRedirect('/preferences');
});

test('failed attempts (a taken username, a bad password) don\'t count', function () {
    config(['user.registrations_per_hour_per_address' => 1]);
    User::factory()->adult()->create(['username' => 'rgt_taken']);

    rgtRegister($this, 'rgt_taken')->assertSessionHasErrors('username');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post(route('register'), ['username' => 'rgt_weak', 'password' => 'x', 'password_confirmation' => 'x'])
        ->assertSessionHasErrors('password');

    rgtRegister($this, 'rgt_finally')->assertRedirect('/preferences');
});

test('signing up with a username only works, and sends no verification email', function () {
    Notification::fake();

    rgtRegister($this, 'rgt_no_email')->assertRedirect('/preferences');

    Notification::assertNothingSent();
    expect(User::query()->where('username', 'rgt_no_email')->value('email'))->toBeNull();
});

test('an IPv6 address counts by its /64, and the logged address is never the address itself', function () {
    config(['user.registrations_per_hour_per_address' => 1]);
    Log::spy();

    rgtRegister($this, 'rgt_v6_first', '2001:db8:1:2::5')->assertRedirect('/preferences');
    rgtRegister($this, 'rgt_v6_second', '2001:db8:1:2:ffff::9')->assertSessionHasErrors('username');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'Registration refused')
        && $context['address'] === ClientAddress::key('2001:db8:1:2::1') && ! str_contains(json_encode($context), '2001'))->once();
    expect(ClientAddress::key('203.0.113.7'))->not->toBe(ClientAddress::key('203.0.113.8'))
        ->and(ClientAddress::key('203.0.113.7'))->not->toContain('203');
});

test('asking for a verification link with no email says so instead of pretending to send one', function () {
    $person = User::factory()->adult()->create(['email' => null, 'email_verified_at' => null]);

    $this->actingAs($person)->post(route('verification.send'))->assertSessionHas('status', 'no-email');
});
