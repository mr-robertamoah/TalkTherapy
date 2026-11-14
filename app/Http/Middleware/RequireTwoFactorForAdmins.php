<?php

namespace App\Http\Middleware;

use App\Support\TwoFactorRequirement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-34d: from the required date, an administrator without two-factor sign-in can do nothing but set it up --
// every request outside a short list of their own account's routes is refused (JSON: 403; a page: sent to their
// profile). Refused by default, not route by route (security review): admin powers also live on shared routes, where
// the controller checks isAdmin() itself (reading therapy content and identity documents, verifying counsellors,
// answering requests, admin channels). Never a lockout: they stay signed in, and their profile, setting it up, the
// email and password pages, their notices and signing out all work.
class RequireTwoFactorForAdmins
{
    public const MESSAGE = 'Two-factor sign-in is required for administrators. Set it up on your profile to go on.';

    // Their own account only: the profile and what its page loads, setting two-factor up, email, password, notices,
    // signing out, and whether the page is still signed in (SCRUM-423).
    private const OPEN = [
        'profile.*', 'two_factor.*', 'password.*', 'verification.*', 'logout', 'session.check',
        'notifications.*', 'safety_notice.current', 'help.requests.current', 'api.users.guardianship', 'api.testimonials',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // As EnsureAccountIsNotSuspended: the api group runs before auth:sanctum, so a token caller isn't on the
        // session guard yet.
        $user = $request->user() ?? $request->user('sanctum');

        if (! TwoFactorRequirement::blocks($user) || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return redirect()->route('profile.show');
    }
}
