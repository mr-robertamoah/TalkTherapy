<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-34: the two-factor routes are a plain 404 until TWO_FACTOR_ENABLED is switched on (like the safety flags).
class EnsureTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('auth.two_factor.enabled'), 404);

        return $next($request);
    }
}
