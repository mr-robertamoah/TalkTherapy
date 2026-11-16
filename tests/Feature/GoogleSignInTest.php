<?php

use App\Actions\Auth\ResolveGoogleAccountAction;
use App\Http\Controllers\Auth\GoogleSignInController;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\User;
use App\Support\TwoFactorCodes;
use Database\Seeders\GoogleSignInSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;

// SCRUM-453 (Google sign-in, SCRUM-112): "Continue with Google" for accounts already connected to Google.

beforeEach(function () {
    config(['auth.google.enabled' => true, 'services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'https://talktherapy.test/auth/google/callback']);
});

// Socialite's google driver, swapped in whole (so a test can swap it again). The callback must read it with PKCE.
function gsiProvider(MockInterface $provider): void
{
    $provider->shouldReceive('enablePKCE')->atLeast()->once()->andReturnSelf();
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($provider);
    Socialite::swap($socialite);
}

// Socialite answers the callback with this Google account.
function gsiGoogle(string $sub, ?string $email = 'someone@gmail.com', mixed $emailVerified = true): void
{
    $google = (new SocialiteUser)->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => $emailVerified])->map(['id' => $sub, 'email' => $email]);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($google);
    gsiProvider($provider);
}

// Socialite throws this when the callback is read.
function gsiGoogleFails(Throwable $e): void
{
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andThrow($e);
    gsiProvider($provider);
}

// Fails the test if Google is asked anything.
function gsiGoogleUntouched(): void
{
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->never();
    Socialite::swap($socialite);
}

function gsiLinked(string $sub, array $attributes = []): User
{
    $user = User::factory()->adult()->create($attributes);
    $user->forceFill(['google_id' => $sub, 'google_linked_at' => now()])->save();

    return $user->fresh();
}

test('while switched off, both Google routes are a plain 404', function () {
    config(['auth.google.enabled' => false]);

    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/google/callback?code=x&state=y')->assertNotFound();
});

test('the redirect goes to Google, asking which account, for openid, email and profile only', function () {
    $response = $this->get(route('auth.google.redirect'))->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(parse_url($location, PHP_URL_HOST))->toBe('accounts.google.com')
        ->and($query['prompt'])->toBe('select_account')
        ->and(collect(explode(' ', $query['scope']))->sort()->values()->all())->toBe(['email', 'openid', 'profile'])
        ->and($query['client_id'])->toBe('test-client-id')
        // Never stateless: the state is kept in this browser's session.
        ->and($query['state'])->toBe(session('state'))
        // PKCE: the verifier stays in the session, only its S256 challenge goes to Google.
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', session('code_verifier'), true)), '+/', '-_'), '='));
});

test('while switched on but not fully configured, the routes are a 404 and the button is hidden', function (string $missing) {
    config([$missing => null]);

    $this->get(route('auth.google.redirect'))->assertNotFound();
    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('googleSignIn', false));
})->with(['services.google.client_id', 'services.google.redirect']);

test('a connected account without two-factor is signed in, with a new session and nothing remembered', function () {
    $user = gsiLinked('sub-123');
    gsiGoogle('sub-123');
    $this->get(route('login'));
    $before = session()->getId();

    $response = $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']));

    $response->assertRedirect(route('home', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
    $response->assertCookieMissing(Auth::guard('web')->getRecallerName());
    expect($user->fresh()->remember_token)->toBe($user->remember_token);
});

test('a connected account with two-factor goes to the code step, still signed out', function () {
    $user = gsiLinked('sub-2fa');
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();
    gsiGoogle('sub-2fa');

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('two_factor.challenge'));

    $this->assertGuest();
});

test('a Google account whose email Google has not confirmed is refused, even if connected', function (mixed $verified) {
    gsiLinked('sub-unverified');
    gsiGoogle('sub-unverified', emailVerified: $verified);

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => ResolveGoogleAccountAction::UNVERIFIED]);

    $this->assertGuest();
})->with([false, 'true', 1, null]);

test('a Google account connected to nothing gets the same answer whether or not its email has an account here', function () {
    $existing = User::factory()->adult()->create(['email' => 'taken@gmail.com']);
    $closed = User::factory()->adult()->create(['email' => 'closed@gmail.com']);
    $closed->delete();

    $answers = collect(['nobody@gmail.com' => 'sub-a', 'taken@gmail.com' => 'sub-b', 'closed@gmail.com' => 'sub-c'])
        ->map(function (string $sub, string $email) {
            gsiGoogle($sub, $email);
            $response = $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']));
            $this->assertGuest();

            return [$response->status(), $response->headers->get('Location'), session('errors')?->getBag('default')->get('google')];
        });

    expect($answers->unique()->count())->toBe(1)
        ->and($answers->first())->toBe([302, route('login'), [ResolveGoogleAccountAction::NOT_CONNECTED]])
        // Never linked by email.
        ->and($existing->fresh()->google_id)->toBeNull()
        ->and(User::query()->withTrashed()->whereNotNull('google_id')->count())->toBe(0);
});

test('a closed account still holding a Google id is refused, never signed in or revived', function () {
    $user = gsiLinked('sub-closed');
    $user->delete();
    // Closing clears the link (SCRUM-452); put it back to prove the guard holds if it ever didn't.
    User::query()->withTrashed()->whereKey($user->id)->update(['google_id' => 'sub-closed']);
    gsiGoogle('sub-closed');

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => ResolveGoogleAccountAction::NOT_CONNECTED]);

    $this->assertGuest();
    expect(User::query()->withTrashed()->find($user->id)->trashed())->toBeTrue();
});

test('an anonymised account is refused', function () {
    $user = gsiLinked('sub-anon');
    $user->forceFill(['anonymised_at' => now()])->saveQuietly();
    gsiGoogle('sub-anon');

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))
        ->assertSessionHasErrors(['google' => ResolveGoogleAccountAction::NOT_CONNECTED]);

    $this->assertGuest();
});

test('a suspended account signs in and is then sent to the suspended page, as with a password', function () {
    $user = gsiLinked('sub-suspended');
    $report = new Report(['description' => 'Abusive messages']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate($user);
    $report->save();
    Suspension::create(['user_id' => $user->id, 'report_id' => $report->id, 'scope' => 'account', 'starts_at' => now(), 'public_reason' => 'Seeded for the test.']);
    gsiGoogle('sub-suspended');

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
    $this->get(route('home'))->assertRedirect(route('suspended.show'));
});

test('a callback whose state does not match goes back to sign-in with a neutral message', function () {
    Exceptions::fake();
    gsiGoogleFails(new InvalidStateException);

    $this->withSession(['state' => 'pending-state', 'code_verifier' => 'pending-verifier'])->get(route('auth.google.callback', ['code' => 'x', 'state' => 'wrong']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignInController::FAILED]);

    $this->assertGuest();
    // Expected (an old or replayed callback): not reported.
    Exceptions::assertNothingReported();
    expect(session()->has('state'))->toBeFalse()->and(session()->has('code_verifier'))->toBeFalse();
});

test('any other failure reading Google\'s answer goes back to sign-in with the same message', function () {
    Exceptions::fake();
    gsiGoogleFails(new RuntimeException('token endpoint said: invalid_grant <script>'));

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignInController::FAILED]);

    $this->assertGuest();
    // Possibly a misconfigured client: reported, but never shown.
    Exceptions::assertReported(RuntimeException::class);
});

test('a Google answer without an account id is a failure, not a lookup', function () {
    gsiLinked('');
    gsiGoogle('');

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignInController::FAILED]);

    $this->assertGuest();
});

test('the page asked for before signing in is where a Google sign-in lands', function () {
    $user = gsiLinked('sub-intended');
    $this->get(route('profile.show'))->assertRedirect(route('login'));
    gsiGoogle('sub-intended');

    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('profile.show'));

    $this->assertAuthenticatedAs($user);
});

test('cancelling on Google\'s page goes back to sign-in, without Google\'s error text', function () {
    gsiGoogleUntouched();

    $this->withSession(['state' => 'pending-state', 'code_verifier' => 'pending-verifier'])->get(route('auth.google.callback', ['error' => 'access_denied', 'error_description' => 'The user denied <b>access</b>']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignInController::FAILED]);

    $this->assertGuest();
    // Nothing of the unfinished attempt is left to be replayed.
    expect(session()->has('state'))->toBeFalse()->and(session()->has('code_verifier'))->toBeFalse();
    $this->get(route('login'))->assertDontSee('denied');
});

test('a signed-in visitor can not use the Google routes', function () {
    gsiGoogleUntouched();
    $user = User::factory()->adult()->create();

    $this->actingAs($user)->get(route('auth.google.redirect'))->assertRedirect(route('home', absolute: false));
    $this->actingAs($user)->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('home', absolute: false));
});

test('the sign-in page offers Google only when switched on and a client is configured', function (bool $enabled, ?string $clientId, bool $shown) {
    config(['auth.google.enabled' => $enabled, 'services.google.client_id' => $clientId]);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->component('Auth/Login')->where('googleSignIn', $shown));
})->with([
    'on, configured' => [true, 'test-client-id', true],
    'on, no client' => [true, null, false],
    'on, empty client' => [true, '', false],
    'off, configured' => [false, 'test-client-id', false],
]);

test('the seeded Google accounts: one Google-only, one with two-factor -- and seeding again changes nothing', function () {
    $this->seed(GoogleSignInSeeder::class);
    $this->seed(GoogleSignInSeeder::class);

    $googleOnly = User::query()->where('username', 'google_only_demo')->sole();
    $twoFactor = User::query()->where('username', 'google_2fa_demo')->sole();
    expect($googleOnly->getAttributes()['password'])->toBeNull()
        ->and($googleOnly->google_id)->toBe(GoogleSignInSeeder::GOOGLE_ONLY_SUB)
        ->and($googleOnly->hasVerifiedEmail())->toBeTrue()
        ->and($twoFactor->google_id)->toBe(GoogleSignInSeeder::GOOGLE_2FA_SUB)
        ->and($twoFactor->hasTwoFactorEnabled())->toBeTrue();

    gsiGoogle(GoogleSignInSeeder::GOOGLE_2FA_SUB, $twoFactor->email);
    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('two_factor.challenge'));
    $this->assertGuest();

    gsiGoogle(GoogleSignInSeeder::GOOGLE_ONLY_SUB, $googleOnly->email);
    $this->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('home', absolute: false));
    $this->assertAuthenticatedAs($googleOnly);
});
