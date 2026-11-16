<?php

namespace App\Http\Middleware;

use App\Support\GoogleSignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-452: the Google sign-in routes (SCRUM-454/455) are a plain 404 until GOOGLE_SIGN_IN_ENABLED is switched on --
// and (SCRUM-453) the Google client is configured.
class EnsureGoogleSignInIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(GoogleSignIn::available(), 404);

        return $next($request);
    }
}
