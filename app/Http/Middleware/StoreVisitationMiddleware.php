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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        StoreVisitationJob::dispatch($request);
        return $next($request);
    }
}
