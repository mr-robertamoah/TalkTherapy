<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-359: while someone's whole account is suspended, every web and API request they make --
// broadcast channel authorisation included (/broadcasting/auth runs in the web group) -- is refused,
// except the "suspended" page, its appeal form, signing out and leaving a group. Pages are sent to the suspended
// page; JSON gets a 403 the front end recognises. Sessions are kept in files and can't be revoked
// from the server, so this check on every request is what actually enforces a suspension.
class EnsureAccountIsNotSuspended
{
    public const CODE = 'account_suspended';

    // SCRUM-73: leaving a group is a withdrawal, so a suspended member can still leave.
    private const ALLOWED_ROUTES = ['suspended.show', 'suspended.appeal', 'logout', 'privacy', 'api.group.therapies.leave'];

    public function handle(Request $request, Closure $next): Response
    {
        // The api group runs this before a route's auth:sanctum, so a bearer-token caller isn't on
        // the default (session) guard yet.
        $user = $request->user() ?? $request->user('sanctum');

        if (! $user || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        $suspension = $user->activeSuspension();

        if (! $suspension) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Your account is suspended.',
                'code' => self::CODE,
                'endsAt' => $suspension->ends_at,
            ], 403);
        }

        return redirect()->route('suspended.show');
    }
}
