<?php

use App\Exceptions\AppException;
use App\Http\Middleware\EnsureAccountIsNotSuspended;
use App\Http\Middleware\EnsureSafetyFlagsAreEnabled;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequestsV2;
use App\Http\Middleware\StoreVisitationMiddleware;
use App\Support\AppHost;
use App\Support\FailureResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // SCRUM-395/138: which proxies to trust is set in AppServiceProvider::boot, from config (SCRUM-413: an
        // env() read here returns nothing once config is cached, and the proxy would silently stop being trusted).

        // SCRUM-397: only our own host (APP_URL's, plus TRUSTED_HOSTS) once deployed; off for APP_ENV local and testing.
        $middleware->trustHosts(at: fn () => AppHost::trustedPatterns(), subdomains: false);

        $middleware->web(append: [
            HandleInertiaRequestsV2::class,
            AddLinkHeadersForPreloadedAssets::class,
            // SCRUM-359: after the session has started, so the signed-in user resolves.
            EnsureAccountIsNotSuspended::class,
        ]);

        $middleware->api(append: [
            EnsureFrontendRequestsAreStateful::class,
            // \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            SubstituteBindings::class,
            // SCRUM-359: after EnsureFrontendRequestsAreStateful has started the session.
            EnsureAccountIsNotSuspended::class,
        ]);

        $middleware->append(StoreVisitationMiddleware::class);

        // SCRUM-387: every /administrator page (routes/web.php).
        // SCRUM-32: and the safety flag routes, hidden until SAFETY_FLAGS_ENABLED. One call: alias() replaces.
        $middleware->alias(['admin' => EnsureUserIsAdmin::class, 'safety_flags' => EnsureSafetyFlagsAreEnabled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // SCRUM-374: the app's own refusals (AppException) answer the same way wherever they are
        // thrown -- the base Controller's failureResponse() for the controllers that catch them, this
        // for anything that doesn't (middleware, a controller with no try/catch). Both are
        // App\Support\FailureResponse. A real refusal (4xx) is expected and not reported; an
        // uncoded or 5xx one is a bug and still is (FailureResponse reports a 500).
        // Only for web requests: in a queued job or scheduled task a refusal is unexpected, and its
        // trace is the only record of why the job died.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof AppException && FailureResponse::status($e) < 500 && ! app()->runningInConsole());
        $exceptions->dontReportDuplicates();
        $exceptions->render(fn (AppException $exception, Request $request) => FailureResponse::make($request, $exception));
    })->create();
