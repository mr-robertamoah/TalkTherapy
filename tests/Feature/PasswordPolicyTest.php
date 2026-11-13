<?php

use App\Models\User;
use App\Support\PwnedPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

// SCRUM-35: one policy for every new password -- signing up, resetting, changing it.

function pplChange($test, User $user, string $password)
{
    return $test->actingAs($user)->from('/profile')->put(route('password.update'), [
        'current_password' => 'password', 'password' => $password, 'password_confirmation' => $password,
    ]);
}

// The real breach check, against a faked Have I Been Pwned.
function pplRealBreachCheck(): void
{
    app()->instance(UncompromisedVerifier::class, new PwnedPasswordVerifier(app(HttpFactory::class), config('auth.password_policy.uncompromised_timeout')));
}

test('a new password needs 10 characters, letters and numbers', function (string $password) {
    $user = User::factory()->create();

    pplChange($this, $user, $password)->assertSessionHasErrors('password');
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with(['short9chr', 'onlyletterslong', '12345678901']);

test('a password that meets the policy is accepted', function () {
    $user = User::factory()->create();

    pplChange($this, $user, 'quiet harbour 2026')->assertSessionHasNoErrors();
    expect(Hash::check('quiet harbour 2026', $user->fresh()->password))->toBeTrue();
});

test('a password may not contain the username, the email or the part before the @, in any case', function () {
    $user = User::factory()->create(['username' => 'riverstone', 'email' => 'kwame.mensah@example.com']);

    pplChange($this, $user, 'RiverStone2026x')->assertSessionHasErrors(['password' => 'Your password must not contain your username or email.']);
    pplChange($this, $user, 'kwame.mensah99')->assertSessionHasErrors('password');
    pplChange($this, $user, 'xKWAME.MENSAH@EXAMPLE.COM1')->assertSessionHasErrors('password');
});

test('a short username doesn\'t rule out ordinary words', function () {
    $user = User::factory()->create(['username' => 'ama', 'email' => null]);

    pplChange($this, $user, 'drama class 2026')->assertSessionHasNoErrors();
});

test('signing up checks the username typed on the form', function () {
    $this->post(route('register'), [
        'username' => 'harbourlight', 'password' => 'harbourlight2026', 'password_confirmation' => 'harbourlight2026',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('a breached password is refused, with a reason', function () {
    pplRealBreachCheck();
    $hash = strtoupper(sha1('quiet harbour 2026'));
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':42')]);
    $user = User::factory()->create();

    pplChange($this, $user, 'quiet harbour 2026')->assertSessionHasErrors('password');
    Http::assertSent(fn ($request) => str_contains($request->url(), substr($hash, 0, 5)) && ! str_contains($request->url(), substr($hash, 5)));
});

test('if the breach check can\'t be reached, the password is allowed', function () {
    pplRealBreachCheck();
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);
    $user = User::factory()->create();

    pplChange($this, $user, 'quiet harbour 2026')->assertSessionHasNoErrors();
});

test('the breach check gives up after a few seconds, not thirty', function () {
    app()->forgetInstance(UncompromisedVerifier::class);
    $real = app()->make(UncompromisedVerifier::class);

    $timeout = (fn () => $this->timeout)->call($real);

    expect($real)->toBeInstanceOf(PwnedPasswordVerifier::class)->and($timeout)->toBe(config('auth.password_policy.uncompromised_timeout'));
});

test('existing passwords still sign in', function () {
    $user = User::factory()->create();

    $this->post('/login', ['username' => $user->username, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('the forms are told the policy before anyone types', function () {
    $this->get(route('register'))->assertInertia(fn ($page) => $page->where('passwordPolicy', ['min' => 10, 'uncompromised' => true]));
});

test('resetting checks only the email typed, never the account behind it', function () {
    User::factory()->create(['username' => 'lanternfield', 'email' => 'someone@example.com']);

    $this->post(route('password.store'), [
        'token' => 'not-a-real-token', 'email' => 'someone@example.com',
        'password' => 'lanternfield2026', 'password_confirmation' => 'lanternfield2026',
    ])->assertSessionDoesntHaveErrors('password');

    $this->post(route('password.store'), [
        'token' => 'not-a-real-token', 'email' => 'someone@example.com',
        'password' => 'someone2026xx', 'password_confirmation' => 'someone2026xx',
    ])->assertSessionHasErrors('password');
});

test('with the breach check turned off, a breached password is accepted and the forms don\'t mention it', function () {
    config(['auth.password_policy.uncompromised' => false]);
    pplRealBreachCheck();
    $hash = strtoupper(sha1('quiet harbour 2026'));
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).':42')]);
    $user = User::factory()->create();

    pplChange($this, $user, 'quiet harbour 2026')->assertSessionHasNoErrors();
    Http::assertNothingSent();
    Auth::logout();
    $this->get(route('register'))->assertInertia(fn ($page) => $page->where('passwordPolicy.uncompromised', false));
});

test('signing up checks the email typed too, and the part before the @', function () {
    $this->post(route('register'), [
        'username' => 'brightwater', 'email' => 'kofi.badu@example.com',
        'password' => 'kofi.badu2026', 'password_confirmation' => 'kofi.badu2026',
    ])->assertSessionHasErrors('password');
});

test('with a valid reset token, the account\'s own username is refused too, and the token still works', function () {
    $user = User::factory()->create(['username' => 'lanternfield']);
    $token = Password::createToken($user);

    $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'lanternfield2026', 'password_confirmation' => 'lanternfield2026'])
        ->assertSessionHasErrors('password');

    $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertSessionHasNoErrors();
    expect(Hash::check('quiet harbour 2026', $user->fresh()->password))->toBeTrue();
});

test('when the breach check fails, the password is allowed and never written to the log', function () {
    pplRealBreachCheck();
    Log::spy();
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => fn () => throw new ConnectionException('timed out')]);
    $user = User::factory()->create();

    pplChange($this, $user, 'quiet harbour 2026')->assertSessionHasNoErrors();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context === ['error' => 'ConnectionException'])->once();
    Log::shouldNotHaveReceived('error');
});
