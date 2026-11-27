<?php

use App\Actions\User\AnonymiseClosedAccountAction;
use App\Actions\User\ExportYourDataAction;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

// SCRUM-479 (TT-9.3a): the interface language -- config, the request's language (account > cookie > English), choosing
// one, carrying a choice over on registering and signing in, the shared props, notifications, and Your Data.

// What ENABLED_LOCALES would give: English always, then these (only supported ones).
function lcfEnable(string ...$locales): void
{
    config()->set('locales.enabled', ['en', ...$locales]);
}

function lcfUser(?string $locale = null): User
{
    $user = User::factory()->adult()->create();
    $user->forceFill(['locale' => $locale])->save();

    return $user->fresh();
}

// The language the last request was answered in, as the page was told.
function lcfPageLocale($test, string $uri = '/about'): string
{
    $locale = null;
    $test->get($uri)->assertOk()->assertInertia(function ($page) use (&$locale) {
        $locale = $page->toArray()['props']['locale'];
    });

    return $locale;
}

function lcfSuggested($test, array $headers = []): ?string
{
    $suggested = 'unset';
    $test->withHeaders($headers)->get('/about')->assertOk()->assertInertia(function ($page) use (&$suggested) {
        $suggested = $page->toArray()['props']['suggestedLocale'];
    });

    return $suggested;
}

// Socialite answers Google's callback with this (connected) account -- as GoogleSignInTest does.
function lcfGoogle(string $sub): void
{
    config(['auth.google.enabled' => true, 'services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'https://talktherapy.test/auth/google/callback']);
    $google = (new SocialiteUser)->setRaw(['sub' => $sub, 'email' => 'lcf@gmail.com', 'email_verified' => true])->map(['id' => $sub, 'email' => 'lcf@gmail.com']);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('enablePKCE')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($google);
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($provider);
    Socialite::swap($socialite);
}

// --- config ---

test('the config knows English, Twi and French, each named in itself, and only English is reviewed', function () {
    expect(config('locales.supported'))->toHaveKeys(['en', 'tw', 'fr'])
        ->and(config('locales.supported.tw'))->toBe(['label' => 'Twi', 'intl' => 'ak', 'safety_reviewed' => false, 'legal_reviewed' => false])
        ->and(config('locales.supported.fr'))->toBe(['label' => 'Français', 'intl' => 'fr', 'safety_reviewed' => false, 'legal_reviewed' => false])
        ->and(config('locales.supported.en'))->toBe(['label' => 'English', 'intl' => null, 'safety_reviewed' => true, 'legal_reviewed' => true])
        ->and(config('locales.enabled'))->toBe(['en'])
        ->and(config('app.locale'))->toBe('en')
        ->and(config('app.fallback_locale'))->toBe('en');
});

test('ENABLED_LOCALES keeps only supported codes, drops blanks, and always has English', function (string $env, array $expected) {
    $previous = getenv('ENABLED_LOCALES');
    putenv("ENABLED_LOCALES={$env}");
    $_ENV['ENABLED_LOCALES'] = $_SERVER['ENABLED_LOCALES'] = $env;

    try {
        expect((require base_path('config/locales.php'))['enabled'])->toBe($expected);
    } finally {
        $previous === false ? putenv('ENABLED_LOCALES') : putenv("ENABLED_LOCALES={$previous}");
        unset($_ENV['ENABLED_LOCALES'], $_SERVER['ENABLED_LOCALES']);
    }
})->with([
    'default' => ['', ['en']],
    'all three' => ['en,tw,fr', ['en', 'tw', 'fr']],
    'no English, odd spacing and case' => [' FR , tw ', ['en', 'fr', 'tw']],
    'stray commas and unknown codes' => ['fr,,de, ,es', ['en', 'fr']],
]);

// Safety gate: until TT-9.3b's loader keeps unreviewed safety and legal text English, nothing but English may be on
// by default -- enabling tw or fr is TT-9.3h's step, once that gate has landed.
test('with ENABLED_LOCALES unset, only English is enabled', function () {
    $previous = getenv('ENABLED_LOCALES');
    putenv('ENABLED_LOCALES');
    $saved = [$_ENV['ENABLED_LOCALES'] ?? null, $_SERVER['ENABLED_LOCALES'] ?? null];
    unset($_ENV['ENABLED_LOCALES'], $_SERVER['ENABLED_LOCALES']);

    try {
        expect((require base_path('config/locales.php'))['enabled'])->toBe(['en']);
    } finally {
        if ($previous !== false) {
            putenv("ENABLED_LOCALES={$previous}");
        }
        [$env, $server] = $saved;
        if ($env !== null) {
            $_ENV['ENABLED_LOCALES'] = $env;
        }
        if ($server !== null) {
            $_SERVER['ENABLED_LOCALES'] = $server;
        }
    }
});

// --- the request's language ---

test('English by default, with nothing chosen', function () {
    expect(lcfPageLocale($this))->toBe('en');
});

test('a chosen language: the account first, then this browser, then English', function () {
    lcfEnable('tw', 'fr');

    // The browser's cookie, signed out.
    expect(lcfPageLocale($this->withCookie(Locales::COOKIE, 'fr')))->toBe('fr');

    // The account wins over the cookie.
    $this->actingAs(lcfUser('tw'));
    expect(lcfPageLocale($this->withCookie(Locales::COOKIE, 'fr')))->toBe('tw');

    // An account with none: the cookie.
    $this->actingAs(lcfUser());
    expect(lcfPageLocale($this->withCookie(Locales::COOKIE, 'fr')))->toBe('fr');
});

test('<html lang> is the Intl tag where the app code is not one: Twi is `ak`', function () {
    $this->get('/about')->assertOk()->assertSee('<html lang="en">', false);

    lcfEnable('tw', 'fr');
    $this->withCookie(Locales::COOKIE, 'tw')->get('/about')->assertOk()->assertSee('<html lang="ak">', false);
    $this->withCookie(Locales::COOKIE, 'fr')->get('/about')->assertOk()->assertSee('<html lang="fr">', false);
});

test('a language that is not enabled, or not known, is never applied', function () {
    // fr is supported but not enabled here.
    expect(lcfPageLocale($this->withCookie(Locales::COOKIE, 'fr')))->toBe('en')
        ->and(lcfPageLocale($this->withCookie(Locales::COOKIE, '../../etc')))->toBe('en');

    // An account's saved language that has since been switched off: the cookie, then English.
    lcfEnable('tw');
    $this->actingAs(lcfUser('fr'));
    expect(lcfPageLocale($this->withCookie(Locales::COOKIE, 'tw')))->toBe('tw')
        ->and(lcfPageLocale($this->withCookie(Locales::COOKIE, 'xx')))->toBe('en');
});

test('reading the language adds no query: the signed-in user is the one already resolved', function () {
    lcfEnable('fr');
    $user = lcfUser('fr');
    $request = Request::create('/about', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
    $request->setLaravelSession(app('session')->driver());
    Auth::guard('web')->setUser($user);

    DB::enableQueryLog();
    (new SetLocale)->handle($request, fn () => response('ok'));

    expect(DB::getQueryLog())->toBe([])
        ->and(app()->getLocale())->toBe('fr')
        ->and($request->attributes->get(SetLocale::SUGGESTED))->toBeNull();
});

// Relies on two things: the Referer/Origin being APP_URL (http://localhost in .env.testing), one of Sanctum's stateful
// domains, so the API request starts the session and the web-guard user SetLocale reads; and the test sharing the app
// instance with the request, so app()->getLocale() afterwards is what the request set.
test("the app's own API calls are answered in the chosen language too", function () {
    lcfEnable('fr');
    $user = lcfUser('fr');

    $this->actingAs($user)
        ->withHeaders(['Referer' => config('app.url'), 'Origin' => config('app.url')])
        ->getJson('/api/user')->assertOk();

    expect(app()->getLocale())->toBe('fr');
});

// --- the suggestion ---

test("the browser's language is only suggested, and only an enabled one other than English", function () {
    // Nothing else is on.
    expect(lcfSuggested($this, ['Accept-Language' => 'fr-FR,fr;q=0.9']))->toBeNull();

    lcfEnable('tw', 'fr');

    expect(lcfSuggested($this, ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8']))->toBe('fr')
        // Twi's browser tag is `ak`.
        ->and(lcfSuggested($this, ['Accept-Language' => 'ak-GH,en;q=0.5']))->toBe('tw')
        // Ranked by quality, not by order in the header.
        ->and(lcfSuggested($this, ['Accept-Language' => 'en;q=0.4,fr;q=0.9']))->toBe('fr')
        // English first: English ranked above it means no suggestion, whatever follows.
        ->and(lcfSuggested($this, ['Accept-Language' => 'en-GB,fr;q=0.9,ak;q=0.7']))->toBeNull()
        ->and(lcfSuggested($this, ['Accept-Language' => 'en-US']))->toBeNull()
        // Only the top language counts: one not on offer ranking first means no suggestion.
        ->and(lcfSuggested($this, ['Accept-Language' => 'de-DE,fr;q=0.8']))->toBeNull();

    // Never applied.
    expect(lcfPageLocale($this->withHeaders(['Accept-Language' => 'fr-FR'])))->toBe('en');
});

test('nothing is suggested once a language has been chosen, on the account or in this browser', function () {
    lcfEnable('fr');

    $this->actingAs(lcfUser('en'));
    expect(lcfSuggested($this, ['Accept-Language' => 'fr']))->toBeNull();

    $this->actingAs(lcfUser());
    expect(lcfSuggested($this, ['Accept-Language' => 'fr']))->toBe('fr')
        ->and(lcfSuggested($this->withCookie(Locales::COOKIE, 'en'), ['Accept-Language' => 'fr']))->toBeNull();
});

// --- shared props ---

test('pages get the language, the enabled ones and the suggestion -- and nothing else changes for English', function () {
    $this->get('/about')->assertOk()->assertInertia(fn ($page) => $page
        ->where('locale', 'en')
        ->where('supportedLocales', [['code' => 'en', 'label' => 'English']])
        ->where('suggestedLocale', null));

    lcfEnable('tw', 'fr');
    $this->get('/about')->assertInertia(fn ($page) => $page
        ->where('supportedLocales', [['code' => 'en', 'label' => 'English'], ['code' => 'tw', 'label' => 'Twi'], ['code' => 'fr', 'label' => 'Français']]));
});

test("the chosen language isn't serialized with the account, nor mass assignable", function () {
    $user = lcfUser();
    $user->fill(['locale' => 'fr']);

    expect($user->locale)->toBeNull()
        ->and(lcfUser('fr')->toArray())->not->toHaveKey('locale');
});

// --- choosing one ---

test('a guest choosing a language gets a year-long cookie, and is sent back', function () {
    lcfEnable('fr');

    $response = $this->from('/about')->post(route('locale.update'), ['locale' => 'fr']);

    $response->assertRedirect('/about')->assertCookie(Locales::COOKIE, 'fr');
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Locales::COOKIE);
    expect($cookie->getSameSite())->toBe('lax')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->getTimestamp());
});

test('a signed-in person choosing a language keeps it on their account and in this browser', function () {
    lcfEnable('tw');
    $user = lcfUser();

    $this->actingAs($user)->postJson(route('locale.update'), ['locale' => 'tw'])
        ->assertOk()->assertExactJson(['locale' => 'tw'])->assertCookie(Locales::COOKIE, 'tw');

    expect($user->fresh()->locale)->toBe('tw');
});

test('an unknown or switched-off language is refused', function (mixed $locale) {
    lcfEnable('tw');
    $user = lcfUser();

    $this->actingAs($user)->postJson(route('locale.update'), ['locale' => $locale])
        ->assertJsonValidationErrors('locale')->assertCookieMissing(Locales::COOKIE);

    expect($user->fresh()->locale)->toBeNull();
})->with(['switched off' => 'fr', 'unknown' => 'de', 'empty' => '', 'not a string' => [['en']]]);

test('choosing a language is limited to 20 a minute', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->postJson(route('locale.update'), ['locale' => 'en'])->assertOk();
    }

    $this->postJson(route('locale.update'), ['locale' => 'en'])->assertTooManyRequests();
});

// --- carrying a choice over ---

test('registering keeps the language picked before', function () {
    lcfEnable('fr');

    $this->withCookie(Locales::COOKIE, 'fr')->post('/register', [
        'username' => 'lcfnewuser', 'email' => 'lcf-new@example.com', 'password' => 'quiet-harbour-2026', 'password_confirmation' => 'quiet-harbour-2026',
    ])->assertRedirect('/preferences');

    expect(User::query()->where('username', 'lcfnewuser')->value('locale'))->toBe('fr');
});

test('signing in: an account with no language takes the browser\'s; one with a language keeps it and sets the browser', function () {
    lcfEnable('tw', 'fr');
    $none = lcfUser();
    $this->withCookie(Locales::COOKIE, 'fr')->post('/login', ['email' => $none->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($none);
    expect($none->fresh()->locale)->toBe('fr');

    auth()->logout();
    $chosen = lcfUser('tw');
    $response = $this->withCookie(Locales::COOKIE, 'fr')->post('/login', ['email' => $chosen->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($chosen);
    $response->assertCookie(Locales::COOKIE, 'tw');
    expect($chosen->fresh()->locale)->toBe('tw');
});

test('signing out forgets this browser\'s choice, so the next account on a shared device starts in English', function () {
    lcfEnable('fr');
    $a = lcfUser('fr');
    $b = lcfUser();

    $this->withCookie(Locales::COOKIE, 'fr')->post('/login', ['email' => $a->email, 'password' => 'password'])->assertCookie(Locales::COOKIE, 'fr');
    $this->assertAuthenticatedAs($a);

    $this->withCookie(Locales::COOKIE, 'fr')->post('/logout')->assertCookieExpired(Locales::COOKIE);
    $this->assertGuest();

    // The browser has dropped it: B signs in without one.
    $this->defaultCookies = [];
    $this->post('/login', ['email' => $b->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($b);
    expect($b->fresh()->locale)->toBeNull()
        ->and($a->fresh()->locale)->toBe('fr');
});

test('a session ended because the account changed elsewhere forgets the choice too', function () {
    lcfEnable('fr');
    $user = lcfUser('fr');
    $this->actingAs($user)->withCookie(Locales::COOKIE, 'fr')->get('/about')->assertOk();

    // A new password elsewhere: this session's fingerprint no longer matches (EndStaleSessions).
    $user->forceFill(['password' => 'a-new-password-2026'])->save();

    $this->withCookie(Locales::COOKIE, 'fr')->get('/about')->assertCookieExpired(Locales::COOKIE);
    $this->assertGuest();
});

test('signing in by "remember me" carries the browser\'s language over too', function () {
    lcfEnable('fr');
    $user = lcfUser();
    $user->forceFill(['remember_token' => 'lcf-remember-token'])->save();
    $recaller = $user->id.'|lcf-remember-token|'.$user->getAuthPassword();

    $this->withCookie(Auth::guard('web')->getRecallerName(), $recaller)
        ->withCookie(Locales::COOKIE, 'fr')
        ->get('/about')->assertOk();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->locale)->toBe('fr');
});

test('a switched-off language in the cookie is never saved on sign-in', function () {
    lcfEnable('tw');
    $user = lcfUser();

    $this->withCookie(Locales::COOKIE, 'fr')->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->locale)->toBeNull();
});

test('signing in with Google carries the language over too', function () {
    lcfEnable('fr');
    $user = lcfUser();
    $user->forceFill(['google_id' => 'lcf-sub', 'google_linked_at' => now()])->save();
    lcfGoogle('lcf-sub');

    $this->withCookie(Locales::COOKIE, 'fr')->get(route('auth.google.callback', ['code' => 'x', 'state' => 'y']));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->locale)->toBe('fr');
});

// --- notifications, missing keys ---

test("a queued notification is sent in the person's language, and only an enabled one", function () {
    lcfEnable('fr');
    Notification::fake();
    $notification = new class extends Illuminate\Notifications\Notification implements ShouldQueue
    {
        use Queueable;

        public function via(object $notifiable): array
        {
            return ['mail'];
        }
    };

    $french = lcfUser('fr');
    $french->notify($notification);
    $off = lcfUser('tw');
    $off->notify(clone $notification);

    Notification::assertSentTo($french, $notification::class, fn ($n, $channels, $notifiable, $locale) => $locale === 'fr');
    Notification::assertSentTo($off, $notification::class, fn ($n, $channels, $notifiable, $locale) => $locale === null);
    expect($french->preferredLocale())->toBe('fr')->and($off->preferredLocale())->toBeNull();
});

test('a missing translation key is logged once per request, outside production', function () {
    Log::spy();

    __('lcf.missing_key');
    __('lcf.missing_key');
    __('An English sentence used as its own key.');
    __('validation.custom.email.required');
    app()->setLocale('fr');
    __('An English sentence used as its own key.');

    Log::shouldHaveReceived('warning')->with('Missing translation key.', ['key' => 'lcf.missing_key', 'locale' => 'en'])->once();
    Log::shouldHaveReceived('warning')->with('Missing translation key.', ['key' => 'An English sentence used as its own key.', 'locale' => 'fr'])->once();
    Log::shouldHaveReceived('warning')->twice();
    expect(Lang::has('validation.required'))->toBeTrue();
});

// --- Your Data, closing an account ---

test("the copy of someone's data says which language they chose, and anonymising clears it", function () {
    lcfEnable('fr');
    $user = lcfUser('fr');

    $copy = json_decode(implode('', iterator_to_array(ExportYourDataAction::new()->execute($user), false)), true);

    expect($copy['summary']['account']['locale'])->toBe('fr')
        ->and(User::ANONYMISED_ATTRIBUTES)->toContain('locale');

    $user->delete();
    expect(AnonymiseClosedAccountAction::new()->execute($user->fresh()))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('locale'))->toBeNull();
});
