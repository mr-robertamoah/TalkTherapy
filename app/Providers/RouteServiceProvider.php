<?php

namespace App\Providers;

use App\Actions\VideoConsent\GetWardForVideoConsentableAction;
use App\Models\Therapy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Scoped narrowly to message create/update/delete routes (SCRUM-20 M5) -- the general
        // 'api' limiter above is currently disabled entirely (see bootstrap/app.php), so this is
        // the only throttling active on the API surface right now. 30/minute (one every 2s)
        // comfortably covers a real back-and-forth conversation's burst of sends/edits/deletes
        // while still blocking scripted spam.
        RateLimiter::for('messages', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // TT-3.1e-f/SCRUM-285 (security-review finding, MEDIUM): keyed by WARD, not by the
        // acting user/IP -- a ward can have multiple guardians (RevokeVideoConsentAction's own
        // "any one guardian, no unanimity" rule), so a per-user throttle alone gives colluding or
        // adversarial co-guardians each their own independent 10/min bucket against the SAME
        // child's session, defeating the point of rate-limiting this specific disruptive action
        // (repeatedly force-ending an in-progress video call -- the real, named custody-dispute
        // concern this limiter exists for, per SCRUM-282's own security review). Falls back to
        // per-user/IP only if the ward can't be resolved at all (e.g. an already-invalid
        // therapyId, which the controller itself will reject anyway).
        RateLimiter::for('video-consent-revoke', function (Request $request) {
            $therapy = Therapy::find($request->route('therapyId'));
            $wardId = $therapy ? GetWardForVideoConsentableAction::new()->execute($therapy)?->id : null;

            return Limit::perMinute(10)->by($wardId ?? $request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
