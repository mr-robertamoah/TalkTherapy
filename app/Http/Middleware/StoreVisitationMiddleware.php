<?php

namespace App\Http\Middleware;

use App\Jobs\StoreVisitationJob;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StoreVisitationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // SCRUM-373: health checks are a monitor polling every minute, not visits.
        // SCRUM-34b: nor the sign-in's code step (routes/auth.php) -- the visit is recorded once the person is signed in.
        if (! in_array($request->getPathInfo(), ['/login', '/two-factor-challenge'], true) && ! $request->is('up', 'api/health/*')) {
            StoreVisitationJob::dispatch($request->user(), $request->ip());
        }

        return $next($request);
    }
}
