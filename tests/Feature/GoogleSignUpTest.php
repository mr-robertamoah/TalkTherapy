<?php

use App\Actions\Auth\CreateAccountAction;
use App\Actions\Auth\ResolveGoogleAccountAction;
use App\Http\Controllers\Auth\GoogleSignUpController;
use App\Models\User;
use App\Support\ClientAddress;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

// SCRUM-454 (Google sign-in, SCRUM-112): a Google account connected to nothing starts a new account, made once
// "Finish setting up" is sent. Helpers are file-local and gsu-prefixed (--parallel).

beforeEach(function () {
    config(['auth.google.enabled' => true, 'services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'https://talktherapy.test/auth/google/callback']);
    RateLimiter::clear('registrations:'.ClientAddress::key('127.0.0.1'));
});

// Socialite answers the callback with this Google account -- with a name and photo, which must never be used.
// `hd` only when given (a Google Workspace account).
function gsuGoogle(string $sub, ?string $email = 'new.person@gmail.com', mixed $emailVerified = true, ?string $hd = null): void
{
    $raw = ['sub' => $sub, 'email' => $email, 'email_verified' => $emailVerified, 'name' => 'Gsu Realname', 'picture' => 'https://lh3.googleusercontent.com/gsu-photo'];
    $google = (new SocialiteUser)
        ->setRaw($hd === null ? $raw : [...$raw, 'hd' => $hd])
        ->map(['id' => $sub, 'email' => $email, 'name' => 'Gsu Realname', 'avatar' => 'https://lh3.googleusercontent.com/gsu-photo']);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('enablePKCE')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($google);
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($provider);
    Socialite::swap($socialite);
}

// Back from Google as this account -- signed out first, as a new visitor would be.
function gsuCallback($test, string $sub, ?string $email = 'new.person@gmail.com', mixed $emailVerified = true, ?string $hd = null)
{
    auth()->guard('web')->logout();
    gsuGoogle($sub, $email, $emailVerified, $hd);

    return $test->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']));
}

function gsuFinish($test, array $data = [])
{
    return $test->post(route('auth.google.finish.store'), ['username' => 'gsu_new', ...$data]);
}

function gsuGoogleUsers(): int
{
    return User::query()->withTrashed()->whereNotNull('google_id')->count();
}

test('a Google account connected to nothing goes to "Finish setting up", and no account is made yet', function () {
    $before = User::query()->withTrashed()->count();
    $this->get(route('login'));
    $sessionBefore = session()->getId();

    gsuCallback($this, 'sub-new')->assertRedirect(route('auth.google.finish'));

    $this->assertGuest();
    expect(User::query()->withTrashed()->count())->toBe($before)
        // A new session id, so one fixed before can't be carried in.
        ->and(session()->getId())->not->toBe($sessionBefore)
        // Google's name and photo are never kept.
        ->and(json_encode(session()->all()))->not->toContain('Realname')->not->toContain('gsu-photo');

    $this->get(route('auth.google.finish'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/GoogleFinishSignUp'))
        ->assertDontSee('new.person@gmail.com')
        ->assertDontSee('Realname');
});

test('finishing makes a password-less account connected to Google, with the confirmed email, signed in, on /preferences', function () {
    Event::fake([Registered::class]);
    gsuCallback($this, 'sub-finish');

    gsuFinish($this)->assertRedirect('/preferences');

    $user = User::query()->where('username', 'gsu_new')->sole();
    expect($user->google_id)->toBe('sub-finish')
        ->and($user->google_linked_at)->not->toBeNull()
        ->and($user->getAttributes()['password'])->toBeNull()
        ->and($user->email)->toBe('new.person@gmail.com')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->firstName)->toBeNull()
        ->and($user->lastName)->toBeNull();
    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(Registered::class, fn (Registered $event) => $event->user->is($user));

    // The sign-up is used up: the finish page is gone, and sending again makes nothing.
    auth()->guard('web')->logout();
    $this->get(route('auth.google.finish'))->assertRedirect(route('login'));
    gsuFinish($this, ['username' => 'gsu_again'])->assertRedirect(route('login'));
    expect(User::query()->where('username', 'gsu_again')->exists())->toBeFalse();

    // And continuing with Google now signs in.
    gsuCallback($this, 'sub-finish')->assertRedirect(route('home', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('a kept email is already confirmed, so no verification email is sent', function () {
    Notification::fake();
    gsuCallback($this, 'sub-no-mail');

    gsuFinish($this)->assertRedirect('/preferences');

    Notification::assertNothingSent();
});

test('an email any account here uses is not kept -- and nothing in the responses says so', function () {
    $active = User::factory()->adult()->create(['email' => 'active@gmail.com']);
    $closed = User::factory()->adult()->create(['email' => 'closed@gmail.com']);
    $closed->delete();
    User::factory()->adult()->create(['pending_email' => 'pending@gmail.com', 'pending_email_requested_at' => now()]);
    User::factory()->adult()->create(['email' => null, 'last_verified_email' => 'remembered@gmail.com', 'last_verified_email_at' => now()]);

    $answers = collect(['free@gmail.com', 'active@gmail.com', 'closed@gmail.com', 'pending@gmail.com', 'remembered@gmail.com'])
        ->mapWithKeys(function (string $email, int $i) {
            $callback = gsuCallback($this, "sub-email-{$i}", $email);
            $page = $this->get(route('auth.google.finish'));
            $finish = gsuFinish($this, ['username' => "gsu_email_{$i}"]);

            return [$email => [
                $callback->status(), $callback->headers->get('Location'),
                $page->status(), $page->viewData('page')['component'], array_keys($page->viewData('page')['props']),
                $finish->status(), $finish->headers->get('Location'),
            ]];
        });

    expect($answers->unique()->count())->toBe(1)
        ->and(User::query()->where('username', 'gsu_email_0')->value('email'))->toBe('free@gmail.com');

    foreach ([1, 2, 3, 4] as $i) {
        $user = User::query()->where('username', "gsu_email_{$i}")->sole();
        expect($user->email)->toBeNull()->and($user->email_verified_at)->toBeNull()->and($user->google_id)->toBe("sub-email-{$i}");
    }

    // Never linked by email.
    expect($active->fresh()->google_id)->toBeNull();
});

test('an email taken between Google answering and the form being sent is not kept', function () {
    gsuCallback($this, 'sub-late-email', 'late@gmail.com');
    User::factory()->adult()->create(['email' => 'late@gmail.com']);

    gsuFinish($this)->assertRedirect('/preferences');

    expect(User::query()->where('username', 'gsu_new')->value('email'))->toBeNull();
});

test('a Google account whose email Google has not confirmed is still refused, and starts nothing', function () {
    gsuCallback($this, 'sub-unverified', emailVerified: false)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => ResolveGoogleAccountAction::UNVERIFIED]);

    $this->get(route('auth.google.finish'))->assertRedirect(route('login'));
    expect(gsuGoogleUsers())->toBe(0);
});

test('a sign-up waits 10 minutes, then has expired', function () {
    gsuCallback($this, 'sub-expiring');

    $this->travel(9)->minutes();
    $this->get(route('auth.google.finish'))->assertOk();

    $this->travel(2)->minutes();
    $this->get(route('auth.google.finish'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignUpController::EXPIRED]);
    gsuFinish($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignUpController::EXPIRED]);

    $this->assertGuest();
    expect(gsuGoogleUsers())->toBe(0);
});

test('without a sign-up waiting, both finish routes go back to sign-in', function () {
    $this->get(route('auth.google.finish'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignUpController::EXPIRED]);
    gsuFinish($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignUpController::EXPIRED]);

    expect(User::query()->where('username', 'gsu_new')->exists())->toBeFalse();
});

test('a username is checked as for registration, and a rejected one keeps the sign-up waiting', function (string $username) {
    User::factory()->adult()->create(['username' => 'gsu_taken']);
    gsuCallback($this, 'sub-username');

    gsuFinish($this, ['username' => $username])->assertSessionHasErrors('username');

    expect(gsuGoogleUsers())->toBe(0);
    $this->get(route('auth.google.finish'))->assertOk();
    gsuFinish($this, ['username' => 'gsu_fine'])->assertRedirect('/preferences');
})->with(['taken' => 'gsu_taken', 'blank' => '', 'spaces' => 'gsu has spaces', 'too long' => str_repeat('a', 256)]);

test('a date of birth is optional: given, it is saved; without one the person counts as under 18', function () {
    gsuCallback($this, 'sub-dob');
    gsuFinish($this, ['username' => 'gsu_dob', 'dob' => now()->subYears(25)->toDateString()])->assertRedirect('/preferences');

    gsuCallback($this, 'sub-no-dob');
    gsuFinish($this, ['username' => 'gsu_no_dob', 'dob' => ''])->assertRedirect('/preferences');

    $withDob = User::query()->where('username', 'gsu_dob')->sole();
    $withoutDob = User::query()->where('username', 'gsu_no_dob')->sole();
    expect($withDob->dob->toDateString())->toBe(now()->subYears(25)->toDateString())
        ->and($withDob->isAdult())->toBeTrue()
        ->and($withoutDob->dob)->toBeNull()
        ->and($withoutDob->isAdult())->toBeFalse();
});

test('a date of birth is checked as on the profile', function (string $dob) {
    gsuCallback($this, 'sub-bad-dob');

    gsuFinish($this, ['dob' => $dob])->assertSessionHasErrors('dob');

    expect(gsuGoogleUsers())->toBe(0);
})->with([
    'not a date' => 'yesterday-ish',
    'too young' => fn () => now()->subYears(5)->toDateString(),
    'in the future' => fn () => now()->addYears(30)->toDateString(),
    'today' => fn () => now()->toDateString(),
]);

test('the per-address limit is shared with registration', function () {
    config(['user.registrations_per_hour_per_address' => 1]);
    $this->post(route('register'), ['username' => 'gsu_by_form', 'password' => 'a-Long-password-1', 'password_confirmation' => 'a-Long-password-1'])
        ->assertRedirect('/preferences');

    gsuCallback($this, 'sub-limited');
    gsuFinish($this)->assertSessionHasErrors(['username' => CreateAccountAction::TOO_MANY]);

    expect(gsuGoogleUsers())->toBe(0);
});

test('a Google account connected to an account after Google answered is refused neatly, and the count given back', function () {
    gsuCallback($this, 'sub-raced');
    $other = User::factory()->adult()->create();
    $other->forceFill(['google_id' => 'sub-raced', 'google_linked_at' => now()])->save();

    gsuFinish($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignUpController::EXPIRED]);

    $this->assertGuest();
    expect(User::query()->where('username', 'gsu_new')->exists())->toBeFalse()
        ->and(RateLimiter::attempts('registrations:'.ClientAddress::key('127.0.0.1')))->toBe(0);
    $this->get(route('auth.google.finish'))->assertRedirect(route('login'));
});

test('while switched off, the finish routes are a plain 404', function () {
    config(['auth.google.enabled' => false]);

    $this->get('/auth/google/finish')->assertNotFound();
    $this->post('/auth/google/finish', ['username' => 'gsu_off'])->assertNotFound();
});

test('a signed-in visitor can not use the finish routes', function () {
    $user = User::factory()->adult()->create();

    $this->actingAs($user)->get(route('auth.google.finish'))->assertRedirect(route('home', absolute: false));
    $this->actingAs($user)->post(route('auth.google.finish.store'), ['username' => 'gsu_signed_in'])->assertRedirect(route('home', absolute: false));
});

test('the finish routes are throttled under their own names', function () {
    expect(Route::getRoutes()->getByName('auth.google.finish')->gatherMiddleware())->toContain('throttle:30,1,auth.google.finish')
        ->and(Route::getRoutes()->getByName('auth.google.finish.store')->gatherMiddleware())->toContain('throttle:10,1,auth.google.finish.store');
});

test('the registration page offers Google only when it is available', function (bool $enabled, bool $shown) {
    config(['auth.google.enabled' => $enabled]);

    $this->get(route('register'))->assertInertia(fn ($page) => $page->component('Auth/Register')->where('googleSignIn', $shown));
})->with(['on' => [true, true], 'off' => [false, false]]);

test('an email Google does not vouch for (not Gmail, no Workspace domain) is not kept; a Workspace one is', function () {
    gsuCallback($this, 'sub-other-domain', 'person@example.org');
    $page = $this->get(route('auth.google.finish'));
    gsuFinish($this, ['username' => 'gsu_other_domain'])->assertRedirect('/preferences');

    gsuCallback($this, 'sub-workspace', 'person@workspace.example', hd: 'workspace.example');
    $workspacePage = $this->get(route('auth.google.finish'));
    gsuFinish($this, ['username' => 'gsu_workspace'])->assertRedirect('/preferences');

    gsuCallback($this, 'sub-googlemail', 'Person@GoogleMail.com');
    gsuFinish($this, ['username' => 'gsu_googlemail'])->assertRedirect('/preferences');

    $dropped = User::query()->where('username', 'gsu_other_domain')->sole();
    $workspace = User::query()->where('username', 'gsu_workspace')->sole();
    expect($dropped->email)->toBeNull()->and($dropped->email_verified_at)->toBeNull()->and($dropped->google_id)->toBe('sub-other-domain')
        ->and($workspace->email)->toBe('person@workspace.example')->and($workspace->hasVerifiedEmail())->toBeTrue()
        ->and(User::query()->where('username', 'gsu_googlemail')->value('email'))->toBe('Person@GoogleMail.com')
        // Nothing on the page tells the two apart.
        ->and(array_keys($page->viewData('page')['props']))->toBe(array_keys($workspacePage->viewData('page')['props']));
});

test('showing the sign-in or registration page, or signing in with a password, ends an unfinished sign-up', function (string $leave) {
    $user = User::factory()->adult()->create(['username' => 'gsu_password_user', 'password' => 'a-Long-password-1']);
    gsuCallback($this, 'sub-left');
    $this->get(route('auth.google.finish'))->assertOk();

    match ($leave) {
        'login' => $this->get(route('login'))->assertOk(),
        'register' => $this->get(route('register'))->assertOk(),
        'password' => $this->post(route('login.store'), ['username' => 'gsu_password_user', 'password' => 'a-Long-password-1'])->assertRedirect(),
    };
    if ($leave === 'password') {
        $this->assertAuthenticatedAs($user);
    }

    auth()->guard('web')->logout();
    $this->get(route('auth.google.finish'))->assertRedirect(route('login'));
    gsuFinish($this)->assertRedirect(route('login'));
    expect(gsuGoogleUsers())->toBe(0);
})->with(['sign-in page' => 'login', 'registration page' => 'register', 'password sign-in' => 'password']);

// Something else takes the username, the Google account or the email just before the account is written -- after every
// check has passed (two requests at the same moment). Once only. '@google_id' stands for the new account's Google id.
function gsuClashOnCreate(array $taken): void
{
    $done = false;
    User::creating(function (User $user) use ($taken, &$done) {
        if ($user->username === 'gsu_new' && ! $done) {
            $done = true;
            User::withoutEvents(fn () => User::factory()->adult()->create(array_map(fn ($value) => $value === '@google_id' ? $user->google_id : $value, $taken)));
        }
    });
}

test('a username taken at the same moment comes back as a username error, keeping the sign-up and the count', function () {
    gsuCallback($this, 'sub-clash-username');
    gsuClashOnCreate(['username' => 'gsu_new']);

    gsuFinish($this)->assertSessionHasErrors('username');

    expect(gsuGoogleUsers())->toBe(0)
        ->and(RateLimiter::attempts('registrations:'.ClientAddress::key('127.0.0.1')))->toBe(0);
    $this->get(route('auth.google.finish'))->assertOk();
});

test('a Google account connected at the same moment is refused neatly, with the count given back', function () {
    gsuCallback($this, 'sub-clash-google');
    gsuClashOnCreate(['username' => 'gsu_other', 'google_id' => '@google_id']);

    gsuFinish($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignUpController::EXPIRED]);

    $this->assertGuest();
    expect(User::query()->where('username', 'gsu_new')->exists())->toBeFalse()
        ->and(RateLimiter::attempts('registrations:'.ClientAddress::key('127.0.0.1')))->toBe(0);
});

test('an email taken at the same moment asks to try again, and then the account is made without it', function () {
    gsuCallback($this, 'sub-clash-email');
    gsuClashOnCreate(['username' => 'gsu_email_owner', 'email' => 'new.person@gmail.com']);

    gsuFinish($this)->assertSessionHasErrors(['username' => GoogleSignUpController::TRY_AGAIN]);
    expect(gsuGoogleUsers())->toBe(0);

    gsuFinish($this)->assertRedirect('/preferences');
    expect(User::query()->where('username', 'gsu_new')->sole()->email)->toBeNull();
});
