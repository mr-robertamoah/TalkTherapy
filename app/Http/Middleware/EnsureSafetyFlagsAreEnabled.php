<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-32 (the user's decision): every safety flag route is hidden -- a plain 404 -- until
// SAFETY_FLAGS_ENABLED is switched on, after the Ghana Act 846/843 legal read.
class EnsureSafetyFlagsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('safety.enabled'), 404);

        return $next($request);
    }
}
