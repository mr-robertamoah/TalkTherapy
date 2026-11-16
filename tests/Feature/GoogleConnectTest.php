<?php

use App\Actions\Auth\ConnectGoogleAccountAction;
use App\Actions\Auth\DisconnectGoogleAccountAction;
use App\Actions\TwoFactor\ResetTwoFactorAction;
use App\Actions\User\ApplyDueEmailChangesAction;
use App\Actions\User\ConfirmPendingEmailAction;
use App\Actions\User\GetYourDataAction;
use App\Actions\User\RequestEmailChangeAction;
use App\Enums\AdministratorTypeEnum;
use App\Http\Controllers\Auth\GoogleConnectionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\SetPasswordLinkController;
use App\Http\Middleware\EndStaleSessions;
use App\Models\Administrator;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\GoogleSignInChangedNotification;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\TwoFactorCodes;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

// SCRUM-455 (Google sign-in, SCRUM-112): connecting and disconnecting Google from the profile, "Set a password" for an
// account without one, and what "Your data", the copy and /privacy say about it.

beforeEach(function () {
    config([
        'auth.google.enabled' => true,
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'https://talktherapy.test/auth/google/callback',
        'services.google.connect_redirect' => 'https://talktherapy.test/profile/google/callback',
    ]);
    // Never logoutOtherDevices (it needs a password): other sessions end through the session version.
    Event::fake([OtherDeviceLogout::class]);
});

afterEach(fn () => Event::assertNotDispatched(OtherDeviceLogout::class));

// Socialite answers the connect callback with this Google account -- read with the connect callback URL and PKCE.
function gcoGoogle(string $sub, ?string $email = 'someone@gmail.com', mixed $emailVerified = true): void
{
    $google = (new SocialiteUser)->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => $emailVerified])->map(['id' => $sub, 'email' => $email]);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirectUrl')->with('https://talktherapy.test/profile/google/callback')->andReturnSelf();
    $provider->shouldReceive('enablePKCE')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($google);
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($provider);
    Socialite::swap($socialite);
}

// Fails the test if Google is asked anything.
function gcoGoogleUntouched(): void
{
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->never();
    Socialite::swap($socialite);
}

function gcoUser(array $attributes = []): User
{
    return User::factory()->adult()->create(['email_verified_at' => now(), 'remember_token' => 'remembered', ...$attributes]);
}

function gcoLinked(string $sub, array $attributes = []): User
{
    $user = gcoUser($attributes);
    $user->forceFill(['google_id' => $sub, 'google_linked_at' => now()->subMonth()])->save();

    return $user->fresh();
}

function gcoPasswordless(array $attributes = []): User
{
    $user = gcoUser($attributes);
    $user->forceFill(['password' => null])->save();

    return $user->fresh();
}

// A connect asked for from this session's profile.
function gcoIntent(User $user, int $secondsAgo = 0): array
{
    return [GoogleConnectionController::INTENT => ['user_id' => $user->id, 'at' => now()->getTimestamp() - $secondsAgo]];
}

function gcoCallback($test, User $user, ?array $session = null)
{
    return $test->actingAs($user)->withSession($session ?? gcoIntent($user))
        ->get(route('profile.google.callback', ['code' => 'x', 'state' => 'y']));
}

// --- Connecting -------------------------------------------------------------------------------

test('connecting asks for the password, then goes to Google with its own callback URL, asking which account', function () {
    $user = gcoUser();

    $response = $this->actingAs($user)->post(route('profile.google.connect'), ['current_password' => 'password'])->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect(parse_url($location, PHP_URL_HOST))->toBe('accounts.google.com')
        ->and($query['redirect_uri'])->toBe('https://talktherapy.test/profile/google/callback')
        ->and($query['prompt'])->toBe('select_account')
        ->and(collect(explode(' ', $query['scope']))->sort()->values()->all())->toBe(['email', 'openid', 'profile'])
        ->and($query['state'])->toBe(session('state'))
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and(session(GoogleConnectionController::INTENT)['user_id'])->toBe($user->id);
});

test('an Inertia visit is sent on to Google as a full-page visit', function () {
    $this->actingAs(gcoUser())->post(route('profile.google.connect'), ['current_password' => 'password'], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

test('connecting needs the right password -- nothing is started without it', function (?string $password) {
    gcoGoogleUntouched();
    $user = gcoUser();

    $this->actingAs($user)->from(route('profile.show'))->post(route('profile.google.connect'), ['current_password' => $password])
        ->assertRedirect(route('profile.show'))->assertSessionHasErrors('current_password');

    expect(session()->has(GoogleConnectionController::INTENT))->toBeFalse();
})->with([null, 'wrong-password']);

test('an account already connected is told so, and isn\'t sent to Google', function () {
    gcoGoogleUntouched();

    $this->actingAs(gcoLinked('sub-mine'))->from(route('profile.show'))->post(route('profile.google.connect'), ['current_password' => 'password'])
        ->assertSessionHasErrors(['google' => ConnectGoogleAccountAction::ALREADY_CONNECTED]);
});

test('the callback connects the chosen Google account: other sessions end, remember-me elsewhere stops, this one stays', function () {
    Notification::fake();
    $user = gcoUser();
    $fingerprint = EndStaleSessions::fingerprint($user);
    $version = (int) $user->session_version;
    gcoGoogle('sub-new');

    gcoCallback($this, $user)->assertRedirect(route('profile.show').'#profile-google-id')->assertSessionHas('status', 'google-connected');

    $fresh = $user->fresh();
    expect($fresh->google_id)->toBe('sub-new')
        ->and($fresh->google_linked_at)->not->toBeNull()
        ->and($fresh->session_version)->toBe($version + 1)
        ->and($fresh->remember_token)->not->toBe('remembered')
        ->and(EndStaleSessions::fingerprint($fresh))->not->toBe($fingerprint)
        // Used once.
        ->and(session()->has(GoogleConnectionController::INTENT))->toBeFalse();
    $this->get(route('profile.show'))->assertOk();
    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, GoogleSignInChangedNotification::class, fn ($n) => $n->change === GoogleSignInChangedNotification::CONNECTED);
});

test('the callback does nothing without a connect asked for from this session -- or one too old, or for someone else', function (string $case) {
    gcoGoogleUntouched();
    $user = gcoUser();
    $session = match ($case) {
        'none' => [],
        'old' => gcoIntent($user, 11 * 60),
        'another account' => gcoIntent(gcoUser()),
    };

    gcoCallback($this, $user, [...$session, 'state' => 'pending', 'code_verifier' => 'pending'])
        ->assertRedirect(route('profile.show').'#profile-google-id')
        ->assertSessionHasErrors(['google' => GoogleConnectionController::FAILED]);

    expect($user->fresh()->google_id)->toBeNull()
        ->and(session()->has('state'))->toBeFalse()
        ->and(session()->has('code_verifier'))->toBeFalse();
})->with(['none', 'old', 'another account']);

test('cancelling on Google\'s page connects nothing, without Google\'s words', function () {
    gcoGoogleUntouched();
    $user = gcoUser();

    $this->actingAs($user)->withSession(gcoIntent($user))
        ->get(route('profile.google.callback', ['error' => 'access_denied', 'error_description' => 'denied <b>x</b>']))
        ->assertSessionHasErrors(['google' => GoogleConnectionController::FAILED]);

    expect($user->fresh()->google_id)->toBeNull();
});

test('a Google account whose email Google hasn\'t confirmed is refused', function (mixed $verified) {
    $user = gcoUser();
    gcoGoogle('sub-unverified', emailVerified: $verified);

    gcoCallback($this, $user)->assertSessionHasErrors(['google' => ConnectGoogleAccountAction::UNVERIFIED]);

    expect($user->fresh()->google_id)->toBeNull()->and($user->fresh()->session_version)->toBe((int) $user->session_version);
})->with([false, 'true', null]);

test('a Google account connected to another account -- a closed one too -- is refused, neutrally', function (bool $closed) {
    $other = gcoLinked('sub-taken');
    if ($closed) {
        $other->delete();
        // Closing clears the link; put it back to prove the check holds if it ever didn't.
        User::query()->withTrashed()->whereKey($other->id)->update(['google_id' => 'sub-taken']);
    }
    $user = gcoUser();
    gcoGoogle('sub-taken');

    gcoCallback($this, $user)->assertSessionHasErrors(['google' => ConnectGoogleAccountAction::TAKEN]);

    expect($user->fresh()->google_id)->toBeNull()
        ->and(User::query()->withTrashed()->find($other->id)->google_id)->toBe('sub-taken');
})->with(['open' => false, 'closed' => true]);

test('an account already connected to a different Google account is refused', function () {
    $user = gcoLinked('sub-first');
    gcoGoogle('sub-second');

    gcoCallback($this, $user)->assertSessionHasErrors(['google' => ConnectGoogleAccountAction::ALREADY_CONNECTED]);

    expect($user->fresh()->google_id)->toBe('sub-first');
});

test('never connected by email: a Google account with this account\'s address but its own id connects only as asked', function () {
    $owner = gcoUser(['email' => 'owner@gmail.com']);
    $user = gcoUser();
    gcoGoogle('sub-x', 'owner@gmail.com');

    gcoCallback($this, $user)->assertSessionHas('status', 'google-connected');

    expect($user->fresh()->google_id)->toBe('sub-x')->and($owner->fresh()->google_id)->toBeNull();
});

// --- Disconnecting ----------------------------------------------------------------------------

test('disconnecting asks for the password, then lets go: other sessions end, remember-me elsewhere stops, this one stays', function () {
    Notification::fake();
    $user = gcoLinked('sub-bye');
    $version = (int) $user->session_version;

    $this->actingAs($user)->from(route('profile.show'))->delete(route('profile.google.disconnect'), ['current_password' => 'wrong'])
        ->assertSessionHasErrors('current_password');
    expect($user->fresh()->google_id)->toBe('sub-bye');

    $this->actingAs($user)->from(route('profile.show'))->delete(route('profile.google.disconnect'), ['current_password' => 'password'])
        ->assertRedirect(route('profile.show'))->assertSessionHas('status', 'google-disconnected');

    $fresh = $user->fresh();
    expect($fresh->google_id)->toBeNull()
        ->and($fresh->google_linked_at)->toBeNull()
        ->and($fresh->session_version)->toBe($version + 1)
        ->and($fresh->remember_token)->not->toBe('remembered');
    $this->get(route('profile.show'))->assertOk();
    Notification::assertSentTo($user, GoogleSignInChangedNotification::class, fn ($n) => $n->change === GoogleSignInChangedNotification::DISCONNECTED);
});

test('an account without a password can\'t disconnect Google -- it is told to set one', function () {
    $user = gcoPasswordless();
    $user->forceFill(['google_id' => 'sub-only', 'google_linked_at' => now()])->save();

    $this->actingAs($user)->from(route('profile.show'))->delete(route('profile.google.disconnect'), ['current_password' => ''])
        ->assertSessionHasErrors(['google' => DisconnectGoogleAccountAction::NEEDS_PASSWORD]);

    expect($user->fresh()->google_id)->toBe('sub-only');
});

// --- Switch, guests, rate limits --------------------------------------------------------------

test('while switched off or not fully configured, connecting is a 404 -- a connected account still sees the card and can disconnect', function (string $missing) {
    gcoGoogleUntouched();
    config([$missing => $missing === 'auth.google.enabled' ? false : null]);
    $user = gcoLinked('sub-off');

    $this->actingAs($user)->post(route('profile.google.connect'), ['current_password' => 'password'])->assertNotFound();
    $this->actingAs($user)->withSession(gcoIntent($user))->get(route('profile.google.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();
    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('googleSignIn.connected', true)->where('googleSignIn.canConnect', false));

    $this->actingAs($user)->from(route('profile.show'))->delete(route('profile.google.disconnect'), ['current_password' => 'password'])
        ->assertSessionHas('status', 'google-disconnected');
    expect($user->fresh()->google_id)->toBeNull();
})->with(['switched off' => 'auth.google.enabled', 'no client' => 'services.google.client_id', 'no connect callback URL' => 'services.google.connect_redirect']);

test('the card: hidden while switched off for an account not connected; shown without "Connect" when there\'s no connect callback URL', function () {
    $user = gcoUser();

    config(['services.google.connect_redirect' => null]);
    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('googleSignIn.connected', false)->where('googleSignIn.canConnect', false));

    config(['auth.google.enabled' => false]);
    $this->actingAs($user)->get(route('profile.show'))->assertInertia(fn ($page) => $page->where('googleSignIn', null));
});

test('a guest can\'t use any of them', function () {
    gcoGoogleUntouched();

    $this->post(route('profile.google.connect'), ['current_password' => 'password'])->assertRedirect(route('login'));
    $this->get(route('profile.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('login'));
    $this->delete(route('profile.google.disconnect'))->assertRedirect(route('login'));
    $this->post(route('profile.password.link'))->assertRedirect(route('login'));
});

test('each route has its own rate limit, named after it', function (string $name) {
    $throttles = collect(app('router')->getRoutes()->getByName($name)->gatherMiddleware())
        ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->values();

    expect($throttles)->toHaveCount(1)->and(explode(',', $throttles[0])[2])->toBe($name);
})->with(['profile.google.connect', 'profile.google.callback', 'profile.google.disconnect', 'profile.password.link']);

// --- The profile ------------------------------------------------------------------------------

test('the profile shows since when Google is connected -- never which Google account', function () {
    $user = gcoLinked('sub-secret-123');

    $response = $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('googleSignIn.connected', true)
            ->where('googleSignIn.connectedAt', $user->google_linked_at->toIso8601String())
            ->where('auth.user.passwordSet', true));

    expect($response->getContent())->not->toContain('sub-secret-123');
    $this->actingAs(gcoUser())->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('googleSignIn.connected', false)->where('googleSignIn.connectedAt', null));
});

test('the profile knows when the account has no password', function () {
    $this->actingAs(gcoPasswordless())->get(route('profile.show'))->assertInertia(fn ($page) => $page->where('auth.user.passwordSet', false));
});

// --- Set a password ---------------------------------------------------------------------------

test('"Set a password" emails the reset link to the account\'s verified address', function () {
    Notification::fake();
    $user = gcoPasswordless();

    $this->actingAs($user)->from(route('profile.show'))->post(route('profile.password.link'))
        ->assertRedirect(route('profile.show'))->assertSessionHas('status', 'password-link-sent');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('"Set a password" is refused without a verified email -- and for an account that has a password', function (string $case) {
    Notification::fake();
    $user = match ($case) {
        'unverified' => gcoPasswordless(['email_verified_at' => null]),
        'no email' => gcoPasswordless(['email' => null, 'email_verified_at' => null]),
        'has a password' => gcoUser(),
    };

    $this->actingAs($user)->from(route('profile.show'))->post(route('profile.password.link'))
        ->assertSessionHasErrors(['password_link' => $case === 'has a password' ? SetPasswordLinkController::HAS_PASSWORD : SetPasswordLinkController::NO_VERIFIED_EMAIL]);

    Notification::assertNothingSent();
})->with(['unverified', 'no email', 'has a password']);

test('the link works while signed in: the password is set and this browser signs in again', function () {
    $user = gcoPasswordless();
    $token = Password::createToken($user);

    $this->actingAs($user)->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();
    $this->actingAs($user)->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(Hash::check('quiet harbour 2026', $user->fresh()->password))->toBeTrue();
});

// --- Account recovery lets go of Google -------------------------------------------------------

test('"This wasn\'t me" disconnects Google too, and the page says so', function () {
    Notification::fake();
    $user = gcoLinked('sub-maybe-intruder', ['email' => 'owner@example.com']);
    RequestEmailChangeAction::new()->execute($user, 'attacker@example.org');
    $fresh = $user->fresh();
    $link = URL::temporarySignedRoute('email.change.stop', now()->addHours(96), ['id' => $fresh->id, 'hash' => $fresh->pendingEmailHash()]);

    $this->post($link)->assertRedirect(route('login'))->assertSessionHas('status', fn (string $status) => str_contains($status, 'Google sign-in'));

    expect($user->fresh()->google_id)->toBeNull()->and($user->fresh()->google_linked_at)->toBeNull();
});

test('the team\'s two-factor reset disconnects Google too, and the notice says so', function () {
    Notification::fake();
    $user = gcoLinked('sub-reset');
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    ResetTwoFactorAction::new()->execute($user->fresh());

    expect($user->fresh()->google_id)->toBeNull()
        ->and($user->fresh()->session_version)->toBe((int) $user->session_version + 1);
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, function (TwoFactorChangedNotification $n) use ($user) {
        return $n->googleDisconnected
            && str_contains(implode(' ', $n->toMail($user)->introLines), 'Google sign-in was disconnected')
            && str_contains(TwoFactorChangedNotification::present($n->toArray($user))->message, 'Google sign-in was disconnected');
    });
});

test('the two-factor reset keeps Google when it is the account\'s only way in (no password, no verified email)', function () {
    Notification::fake();
    $user = gcoPasswordless(['email' => null, 'email_verified_at' => null]);
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user->forceFill(['google_id' => 'sub-only-way', 'google_linked_at' => now(), 'two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    ResetTwoFactorAction::new()->execute($user->fresh());

    expect($user->fresh()->google_id)->toBe('sub-only-way')->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($n) => ! $n->googleDisconnected);
});

// --- Your data, the copy, /privacy ------------------------------------------------------------

test('"What we hold about you" says whether Google is connected and since when -- never the Google id', function () {
    $linked = gcoLinked('sub-yd-999');
    $security = GetYourDataAction::new()->execute($linked)['security'];

    expect($security['googleConnected'])->toBeTrue()
        ->and($security['googleConnectedSince'])->toBe($linked->google_linked_at->toIso8601String())
        ->and(json_encode(GetYourDataAction::new()->execute($linked)))->not->toContain('sub-yd-999')
        ->and(GetYourDataAction::new()->execute(gcoUser())['security'])->toMatchArray(['googleConnected' => false, 'googleConnectedSince' => null]);

    $this->actingAs($linked)->get(route('profile.your-data'))
        ->assertInertia(fn ($page) => $page->where('data.security.googleConnected', true))
        ->assertDontSee('sub-yd-999');
});

test('the downloaded copy says the same, and never holds the Google id', function () {
    $linked = gcoLinked('sub-export-777');

    $response = $this->actingAs($linked)->post(route('profile.your-data.download'), ['current_password' => 'password'])->assertOk();
    $content = $response->streamedContent();
    $copy = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

    expect($copy['summary']['security']['googleConnected'])->toBeTrue()
        ->and($copy['summary']['security']['googleConnectedSince'])->toBe($linked->google_linked_at->toIso8601String())
        ->and($content)->not->toContain('sub-export-777');
});

test('/privacy says what Google sign-in keeps, and how to disconnect it', function () {
    $privacy = file_get_contents(resource_path('js/Pages/Privacy.vue'));

    expect($privacy)->toContain('id="google-sign-in"')
        ->and($privacy)->toContain('Google account\'s id and when you connected it')
        ->and($privacy)->toContain('disconnect Google from your profile');
    $this->get(route('privacy'))->assertOk();
});

// --- Review follow-ups ------------------------------------------------------------------------

test('the security notices say what to do if it wasn\'t you -- connected: disconnect Google AND change the password', function () {
    $user = gcoUser();
    $connected = new GoogleSignInChangedNotification(GoogleSignInChangedNotification::CONNECTED);
    $disconnected = new GoogleSignInChangedNotification(GoogleSignInChangedNotification::DISCONNECTED);

    expect(implode(' ', $connected->toMail($user)->introLines))->toContain('disconnect Google from your profile AND change your password')
        ->and(GoogleSignInChangedNotification::present($connected->toArray($user))->message)->toContain('Disconnect Google from your profile and change your password')
        ->and(implode(' ', $disconnected->toMail($user)->introLines))->toContain('change your password now')->not->toContain('disconnect Google')
        ->and(GoogleSignInChangedNotification::present($disconnected->toArray($user))->message)->toContain('Not you? Change your password now.');
});

test('an email change the team made disconnects Google too, and the previous address is told', function () {
    Notification::fake();
    $user = gcoLinked('sub-team-change', ['email' => 'gco-old@example.com', 'email_verified_at' => now()->subWeek()]);
    $super = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();

    RequestEmailChangeAction::new()->execute($user->fresh(), 'gco-new@example.org', $super);
    ConfirmPendingEmailAction::new()->execute($user->id, (string) $user->fresh()->pendingEmailHash());
    $this->travel((int) config('auth.email_change_wait_hours') + 1)->hours();
    ApplyDueEmailChangesAction::new()->execute();

    expect($user->fresh()->email)->toBe('gco-new@example.org')->and($user->fresh()->google_id)->toBeNull();
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn (EmailChangedNotification $n, array $channels, AnonymousNotifiable $to) => $n->stage === EmailChangedNotification::APPLIED
        && $n->googleDisconnected
        && $to->routes['mail'] === 'gco-old@example.com'
        && str_contains(implode(' ', $n->toMail($to)->introLines), EmailChangedNotification::GOOGLE_DISCONNECTED));
});

test('the person\'s own email change leaves Google connected', function () {
    Notification::fake();
    $user = gcoLinked('sub-own-change', ['email_verified_at' => null]);

    RequestEmailChangeAction::new()->execute($user->fresh(), 'gco-mine@example.org');
    ConfirmPendingEmailAction::new()->execute($user->id, (string) $user->fresh()->pendingEmailHash());

    expect($user->fresh()->email)->toBe('gco-mine@example.org')->and($user->fresh()->google_id)->toBe('sub-own-change');
});

test('a reset of a password the account had, with Google connected, says Google is still connected -- and keeps it', function () {
    $user = gcoLinked('sub-kept');
    $token = Password::createToken($user);

    $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', fn (string $status) => str_contains($status, NewPasswordController::GOOGLE_STILL_CONNECTED));

    expect($user->fresh()->google_id)->toBe('sub-kept');
});

test('"Set a password" on a Google-only account doesn\'t add the Google notice', function () {
    $user = gcoPasswordless();
    $user->forceFill(['google_id' => 'sub-only', 'google_linked_at' => now()])->save();
    $token = Password::createToken($user);

    $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertSessionHas('status', fn (string $status) => ! str_contains($status, 'Google'));
});

test('signed in as one account, another account\'s reset link is refused -- nothing changes, nobody is signed in as it', function () {
    $signedIn = gcoUser();
    $other = gcoUser();
    $before = $other->fresh()->password;
    $token = Password::createToken($other);

    $this->actingAs($signedIn)->get(route('password.reset', ['token' => $token, 'email' => $other->email]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('otherAccount', NewPasswordController::OTHER_ACCOUNT));
    $this->actingAs($signedIn)->from(route('password.reset', ['token' => $token]))
        ->post(route('password.store'), ['token' => $token, 'email' => $other->email, 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertSessionHasErrors(['email' => NewPasswordController::OTHER_ACCOUNT]);

    expect($other->fresh()->password)->toBe($before)
        ->and(Password::tokenExists($other, $token))->toBeTrue();
    $this->assertAuthenticatedAs($signedIn);
});

test('a reset never signs anyone in as the link\'s account', function () {
    $user = gcoUser();
    $token = Password::createToken($user);

    $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
