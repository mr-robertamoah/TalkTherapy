<?php

use App\Actions\Auth\BeginSignInAction;
use App\Actions\Auth\CreateAccountAction;
use App\Jobs\StoreVisitationJob;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Support\ClientAddress;
use App\Support\TwoFactorCodes;
use App\Support\TwoFactorLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

// SCRUM-451: account creation and the start of a sign-in, called directly -- the shared steps the registration form,
// the login form and (next) Google sign-in all go through. Helpers are file-local and caa-prefixed (--parallel).

function caaIp(): string
{
    return '192.0.2.44';
}

function caaRequest(): Request
{
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => caaIp()]);
    $session = app('session.store');
    $session->start();
    $request->setLaravelSession($session);

    return $request;
}

function caaTwoFactorUser(): User
{
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

beforeEach(function () {
    RateLimiter::clear('registrations:'.ClientAddress::key(caaIp()));
});

test('creates the account with a hashed password and counts it against the address', function () {
    $user = CreateAccountAction::new()->execute(caaIp(), fn () => ['username' => 'caa_new', 'email' => 'caa@example.com', 'password' => 'a-Long-password-1']);

    expect($user->exists)->toBeTrue()
        ->and($user->username)->toBe('caa_new')
        ->and($user->email)->toBe('caa@example.com')
        ->and(Hash::check('a-Long-password-1', $user->password))->toBeTrue()
        ->and(RateLimiter::attempts('registrations:'.ClientAddress::key(caaIp())))->toBe(1);
});

test('refuses once the address has reached its limit, without running the input', function () {
    config(['user.registrations_per_hour_per_address' => 1]);
    CreateAccountAction::new()->execute(caaIp(), fn () => ['username' => 'caa_first', 'password' => 'a-Long-password-1']);

    $ran = false;
    expect(fn () => CreateAccountAction::new()->execute(caaIp(), function () use (&$ran) {
        $ran = true;

        return ['username' => 'caa_second', 'password' => 'a-Long-password-1'];
    }))->toThrow(ValidationException::class, CreateAccountAction::TOO_MANY);

    expect($ran)->toBeFalse()
        ->and(User::query()->where('username', 'caa_second')->exists())->toBeFalse()
        ->and(RateLimiter::attempts('registrations:'.ClientAddress::key(caaIp())))->toBe(1);
});

test('input that fails gives its count back', function () {
    expect(fn () => CreateAccountAction::new()->execute(caaIp(), fn () => throw ValidationException::withMessages(['username' => 'taken'])))
        ->toThrow(ValidationException::class);

    expect(RateLimiter::attempts('registrations:'.ClientAddress::key(caaIp())))->toBe(0);
});

test('details without a password key are refused, and the count given back', function () {
    expect(fn () => CreateAccountAction::new()->execute(caaIp(), fn () => ['username' => 'caa_nopass']))
        ->toThrow(InvalidArgumentException::class);

    expect(User::where('username', 'caa_nopass')->exists())->toBeFalse()
        ->and(RateLimiter::attempts('registrations:'.ClientAddress::key(caaIp())))->toBe(0);
});

test('with two-factor on, beginning a sign-in asks for the code and signs nobody in', function () {
    Queue::fake();
    $user = caaTwoFactorUser();
    $request = caaRequest();

    $response = BeginSignInAction::new()->execute($request, $user, true);

    expect($response->getTargetUrl())->toBe(route('two_factor.challenge'))
        ->and(Auth::check())->toBeFalse()
        ->and(TwoFactorLogin::user($request)?->is($user))->toBeTrue()
        ->and(TwoFactorLogin::remember($request))->toBeTrue();
    Queue::assertNotPushed(StoreVisitationJob::class);
});

test('without two-factor, beginning a sign-in signs the person in', function () {
    Queue::fake();
    $user = User::factory()->create();

    $response = BeginSignInAction::new()->execute(caaRequest(), $user, false);

    expect(Auth::id())->toBe($user->id)
        ->and($response->getTargetUrl())->toBe(url(RouteServiceProvider::HOME));
    Queue::assertPushed(StoreVisitationJob::class);
});
