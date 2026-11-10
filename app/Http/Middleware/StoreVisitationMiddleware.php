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
        if ($request->getPathInfo() !== '/login' && ! $request->is('up', 'api/health/*')) {
            StoreVisitationJob::dispatch($request->user(), $request->ip());
        }

        return $next($request);
    }
}
