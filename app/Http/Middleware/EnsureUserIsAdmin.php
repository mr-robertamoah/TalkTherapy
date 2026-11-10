<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-387: the `admin` route middleware on every /administrator page, the admin write routes and
// the /api/administrator JSON routes. A non-admin gets a JSON 403 where JSON is expected, and is
// otherwise sent home with the same message the controllers' own checks give; those checks stay as
// a backstop, and the super-admin-only pages and actions keep their own server checks.
class EnsureUserIsAdmin
{
    public const MESSAGE = 'You are not authorized to visit this page.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return $request->expectsJson()
                ? response()->json(['message' => self::MESSAGE], 403)
                : Redirect::route('home')->with('message', self::MESSAGE);
        }

        return $next($request);
    }
}
