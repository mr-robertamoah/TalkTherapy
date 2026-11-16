<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-452: the Google sign-in routes (SCRUM-454/455) are a plain 404 until GOOGLE_SIGN_IN_ENABLED is switched on.
class EnsureGoogleSignInIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('auth.google.enabled'), 404);

        return $next($request);
    }
}
