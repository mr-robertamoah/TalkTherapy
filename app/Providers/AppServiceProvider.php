<?php

namespace App\Providers;

use App\Contracts\PushSender;
use App\Listeners\FailHealthCheckOnPendingMigrations;
use App\Models\User;
use App\Notifications\TwoFactorResetRequestedNotification;
use App\Services\AppService;
use App\Support\AppHost;
use App\Support\Locales;
use App\Support\MinishlinkPushSender;
use App\Support\MissingTranslationKeys;
use App\Support\PasswordRules;
use App\Support\PwnedPasswordVerifier;
use App\Support\TrustedProxies;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SCRUM-399: web push for urgent help rings (tests bind a fake).
        $this->app->bind(PushSender::class, MinishlinkPushSender::class);

        // SCRUM-35: Have I Been Pwned answers within a few seconds or the password is allowed (the verifier already fails
        // open; its default timeout is 30s). extend(), not singleton(): ValidationServiceProvider is deferred and would
        // re-register its own binding over ours when it loads.
        $this->app->extend(UncompromisedVerifier::class, fn ($verifier, $app) => new PwnedPasswordVerifier(
            $app[HttpFactory::class],
            config('auth.password_policy.uncompromised_timeout'),
        ));

        // SCRUM-479: scoped -- each request or queued job logs a missing translation key afresh.
        $this->app->scoped(MissingTranslationKeys::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SCRUM-424 (ops review): a two-factor reset by the team only applies once its warning email went out.
        Event::listen(NotificationSent::class, fn (NotificationSent $event) => TwoFactorResetRequestedNotification::markSent($event));

        // SCRUM-35: the policy every new password meets (PasswordRules adds "not your username or email").
        Password::defaults(function () {
            $policy = PasswordRules::basics();

            return config('auth.password_policy.uncompromised') ? $policy->uncompromised() : $policy;
        });

        // SCRUM-397: absolute links on APP_URL once deployed, never on the request's Host header.
        AppHost::enforceLinks($this->app);

        // SCRUM-395/138: the proxy's own addresses -- only explicit ones (TrustedProxies) -- and only the headers it
        // must tell us. From config, so it holds once config is cached (SCRUM-413). Set before any request reaches
        // the global TrustProxies middleware. An entry that would trust more is dropped, which fails safe -- but
        // silently brings back "everyone shares one address", so it's said once per boot.
        $configured = (string) config('app.trusted_proxies');
        if ($proxies = TrustedProxies::fromEnv($configured)) {
            TrustProxies::at($proxies);
            TrustProxies::withHeaders(TrustedProxies::HEADERS);
        }
        if ($ignored = TrustedProxies::ignored($configured)) {
            Log::warning('TRUSTED_PROXIES entries ignored.', ['ignored' => $ignored]);
        }

        // SCRUM-371/374: the one rule for "answer with JSON or redirect back" -- success and failure
        // alike. JSON for every request except an Inertia visit (which must get a redirect, never
        // plain JSON) and a browser navigation, which asks for HTML. Not acceptsJson(), which is true
        // for a browser's `*/*`, nor expectsJson() alone: Inertia sends X-Requested-With, and a
        // request with no Accept header must keep getting JSON.
        Request::macro('wantsJsonResponse', function (): bool {
            /** @var Request $this */
            return ! $this->header('X-Inertia') && ! str_contains((string) $this->header('Accept'), 'text/html');
        });

        // SCRUM-254: user-written text (names, reasons, notes) goes into mail lines and greetings,
        // which are rendered as Markdown -- "[click](https://evil)" would become a real link. This
        // escapes "[" and "<" in every {{ }} echo of the HTML mail, so nothing a user typed can
        // turn into a link, image or tag. The plain-text part is untouched (no stray backslashes).
        Markdown::withSecuredEncoding();

        // SCRUM-363: "contains" search that matches %, _ and ! literally (escaped, with an explicit
        // ESCAPE so MySQL and SQLite agree), across one or more columns OR-ed inside one group.
        QueryBuilder::macro('whereContains', function (array|string $columns, string $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

            return $this->where(function ($query) use ($columns, $pattern) {
                foreach ((array) $columns as $column) {
                    $query->orWhereRaw($query->getGrammar()->wrap($column)." like ? escape '!'", [$pattern]);
                }
            });
        });

        // SCRUM-82: previously there was no operational visibility into failed queue jobs at
        // all. AppService::alertAdminsOfFailedJob() does the actual logging/notifying so it can
        // be unit tested directly, without needing to dispatch a real failing job.
        Queue::failing(function (JobFailed $jobFailed) {
            AppService::new()->alertAdminsOfFailedJob($jobFailed);
        });

        // SCRUM-109: makes the /up health check (configured in bootstrap/app.php) visibly fail
        // if the deploy pipeline's `migrate --force` step was ever skipped or failed silently.
        Event::listen(DiagnosingHealth::class, FailHealthCheckOnPendingMigrations::class);

        // SCRUM-479 (TT-9.3a): a language picked before registering or signing in (the tt_locale cookie) is kept by an
        // account that has none yet; an account that has one keeps it, and this browser is set to it. Every sign-in
        // path fires Login (Auth::login: password, two-factor, Google -- SCRUM-452), and registration fires Registered.
        Event::listen([Registered::class, Login::class], function (Registered|Login $event) {
            if ($event->user instanceof User) {
                Locales::carryOver($event->user, request());
            }
        });
        // And signing out forgets this browser's choice (shared devices) -- a sign-out, and a session ended because the
        // account changed elsewhere (EndStaleSessions: CurrentDeviceLogout).
        Event::listen([Logout::class, CurrentDeviceLogout::class], fn () => Locales::forget());

        // SCRUM-479: a key with no translation is found while developing and testing, not by a reader. Never in
        // production (a log line per page view for a known gap).
        if (! $this->app->isProduction()) {
            Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $locale) {
                $this->app->make(MissingTranslationKeys::class)->report($key, $locale);

                return null;
            });
        }
    }
}
