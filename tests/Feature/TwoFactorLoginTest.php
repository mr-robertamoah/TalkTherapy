<?php

use App\Jobs\StoreVisitationJob;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\TwoFactorCodes;
use App\Support\TwoFactorLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-34b: signing in with two-factor on -- the password, then a code; nobody is signed in in between.

// An account with two-factor on (set directly); returns [user, secret, recovery codes].
function tflUser(array $attributes = []): array
{
    $secret = TwoFactorCodes::newSecret();
    [$codes, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user = User::factory()->create($attributes);
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    return [$user->fresh(), $secret, $codes];
}

function tflCode(string $secret, int $steps = 0): string
{
    $google2fa = new Google2FA;

    return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $steps);
}

function tflPassword($test, User $user, array $extra = [])
{
    return $test->post('/login', ['username' => $user->username, 'password' => 'password', ...$extra]);
}

test('without two-factor, the password signs in as before', function () {
    $user = User::factory()->create();

    tflPassword($this, $user)->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('with two-factor, the right password leads to the code step -- not signed in yet', function () {
    [$user] = tflUser();

    tflPassword($this, $user)->assertRedirect(route('two_factor.challenge'));

    $this->assertGuest();
    $this->get(route('two_factor.challenge'))->assertOk()->assertInertia(fn ($page) => $page->component('Auth/TwoFactorChallenge'));
    $this->get(route('profile.show'))->assertRedirect(route('login'));
});

test('a wrong password never reaches the code step', function () {
    [$user] = tflUser();

    $this->post('/login', ['username' => $user->username, 'password' => 'wrong-password'])->assertSessionHasErrors('username');

    $this->get(route('two_factor.challenge'))->assertRedirect(route('login'));
});

test('the right app code signs in, with "remember me" kept from the password step', function () {
    [$user, $secret] = tflUser();

    tflPassword($this, $user, ['remember' => 'on']);
    $response = $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)]);

    $response->assertRedirect(route('home', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')))->toBeTrue()
        ->and(session('two_factor.login'))->toBeNull();
});

test('the session id changes at each step', function () {
    [$user, $secret] = tflUser();
    $this->get('/login');
    $first = session()->getId();

    tflPassword($this, $user);
    $second = session()->getId();
    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)]);

    expect($second)->not->toBe($first)
        ->and(session()->getId())->not->toBe($second);
});

test('a wrong code is refused and leaves the person signed out', function () {
    [$user] = tflUser();

    tflPassword($this, $user);
    $this->post(route('two_factor.challenge.store'), ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('five wrong codes lock the account\'s codes, even a right one', function () {
    [$user, $secret] = tflUser();

    tflPassword($this, $user);
    foreach (range(1, 5) as $ignored) {
        $this->post(route('two_factor.challenge.store'), ['code' => 'wrong-code']);
    }

    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)])
        ->assertSessionHasErrors(['code' => 'Too many wrong codes. Please try again in 15 minutes.']);
    $this->assertGuest();
});

test('a recovery code signs in once, and the person is told', function () {
    Notification::fake();
    [$user, , $codes] = tflUser();

    tflPassword($this, $user);
    $this->post(route('two_factor.challenge.store'), ['code' => $codes[0]])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(7);
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RECOVERY_CODE_USED);

    Auth::logout();
    tflPassword($this, $user);
    $this->post(route('two_factor.challenge.store'), ['code' => $codes[0]])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('the code step expires after 5 minutes', function () {
    [$user, $secret] = tflUser();

    tflPassword($this, $user);
    $this->travel(TwoFactorLogin::MINUTES + 1)->minutes();

    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)])->assertRedirect(route('login'));
    $this->assertGuest();
});

test('the code step needs the password step first', function () {
    [, $secret] = tflUser();

    $this->get(route('two_factor.challenge'))->assertRedirect(route('login'));
    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)])->assertRedirect(route('login'));
    $this->assertGuest();
});

test('turning the feature off never skips a code someone turned on', function () {
    config(['auth.two_factor.enabled' => false]);
    [$user, $secret] = tflUser();

    tflPassword($this, $user)->assertRedirect(route('two_factor.challenge'));
    $this->assertGuest();

    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)]);
    $this->assertAuthenticatedAs($user);
});

test('the password is checked against the policy at the password step, never carried to the code step', function () {
    [$user] = tflUser(['password' => Hash::make('password')]);

    tflPassword($this, $user);

    expect($user->fresh()->password_weak_reason)->not->toBeNull()
        ->and(collect(session()->all())->flatten()->contains('password'))->toBeFalse();
});

// Another device's session: signed in, then the account changes elsewhere; read afresh, as its next request would be.
function tflOtherDeviceAfter($test, User $user, Closure $change)
{
    $test->post('/login', ['username' => $user->username, 'password' => 'password']);
    $test->get(route('profile.show'))->assertOk();

    $change($user->fresh());
    app('auth')->forgetGuards();

    return $test->get(route('profile.show'));
}

test('a new password elsewhere signs this session out', function () {
    $user = User::factory()->create();

    tflOtherDeviceAfter($this, $user, fn (User $user) => $user->forceFill(['password' => Hash::make('password')])->save())
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

test('two-factor turned on elsewhere signs this session out', function () {
    $user = User::factory()->create();

    tflOtherDeviceAfter($this, $user, fn (User $user) => $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save())
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

test('two-factor turned off elsewhere signs this session out', function () {
    [$user, $secret] = tflUser();
    tflPassword($this, $user);
    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)]);
    $this->get(route('profile.show'))->assertOk();

    $user->fresh()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    app('auth')->forgetGuards();

    $this->get(route('profile.show'))->assertRedirect(route('login'));
});

test('the session that turns it on, makes new codes or turns it off stays signed in', function () {
    $user = User::factory()->create();
    tflPassword($this, $user);

    $secret = $this->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');
    $codes = $this->postJson(route('two_factor.confirm'), ['code' => tflCode($secret)])->assertOk()->json('recoveryCodes');
    $this->get(route('profile.show'))->assertOk();

    $codes = $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => $codes[0]])->assertOk()->json('recoveryCodes');
    $this->get(route('profile.show'))->assertOk();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();

    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => $codes[0]])->assertNoContent();
    $this->get(route('profile.show'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('turning it on or off, or new recovery codes, stop "remember me" elsewhere', function () {
    $user = User::factory()->create(['remember_token' => 'remembered']);
    $secret = $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');
    $codes = $this->postJson(route('two_factor.confirm'), ['code' => tflCode($secret)])->json('recoveryCodes');
    $afterOn = $user->fresh()->remember_token;
    $hash = $user->fresh()->password;

    $codes = $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => $codes[0]])->json('recoveryCodes');
    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => $codes[0]])->assertNoContent();

    // On: a new token. New codes: a new password hash (the cookie carries it). Off: a new token again.
    expect($afterOn)->not->toBe('remembered')
        ->and($user->fresh()->password)->not->toBe($hash)
        ->and($user->fresh()->remember_token)->not->toBe($afterOn);
});

test('wrong codes anywhere count towards one limit, and a right code clears it', function () {
    [$user, $secret, $codes] = tflUser();

    // Four wrong while signed in (turning it off), then a right one clears the count ...
    foreach (range(1, 4) as $ignored) {
        $this->actingAs($user)->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => 'wrong-code'])->assertUnprocessable();
    }
    $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => $codes[0]])->assertOk();

    // ... so five more wrong ones are needed -- here split across new codes and sign-in -- before even a right code is
    // refused. (Each route also has its own per-minute ceiling.)
    foreach (range(1, 3) as $ignored) {
        $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => 'wrong-code'])->assertUnprocessable();
    }
    Auth::logout();
    $this->flushSession();
    tflPassword($this, $user);
    $this->post(route('two_factor.challenge.store'), ['code' => 'wrong-code'])->assertSessionHasErrors('code');
    $this->post(route('two_factor.challenge.store'), ['code' => 'wrong-code'])->assertSessionHasErrors('code');

    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)])
        ->assertSessionHasErrors(['code' => 'Too many wrong codes. Please try again in 15 minutes.']);
    $this->assertGuest();
});

test('a password reset meanwhile cancels a sign-in waiting for its code', function () {
    [$user, $secret] = tflUser();

    tflPassword($this, $user);
    $user->forceFill(['password' => Hash::make('a brand new password 1')])->save();

    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)])->assertRedirect(route('login'));
    $this->assertGuest();
});

test('without "remember me", no remember cookie', function () {
    [$user, $secret] = tflUser();

    tflPassword($this, $user);
    $response = $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)]);

    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')))->toBeFalse();
});

test('someone signed in is not shown the code step', function () {
    $this->actingAs(User::factory()->create())->get(route('two_factor.challenge'))->assertRedirect();
});

test('with no sign-in waiting, even an empty code goes back to sign in', function () {
    $this->post(route('two_factor.challenge.store'), ['code' => ''])->assertRedirect(route('login'));
});

test('a visit is recorded on a sign-in without two-factor too', function () {
    Queue::fake();

    tflPassword($this, User::factory()->create());

    Queue::assertPushed(StoreVisitationJob::class, 1);
});

test('a visit is recorded once signed in, not at the code step', function () {
    Queue::fake();
    [$user, $secret] = tflUser();

    tflPassword($this, $user);
    $this->get(route('two_factor.challenge'))->assertOk();
    Queue::assertNotPushed(StoreVisitationJob::class);

    $this->post(route('two_factor.challenge.store'), ['code' => tflCode($secret)]);
    Queue::assertPushed(StoreVisitationJob::class, 1);
});
