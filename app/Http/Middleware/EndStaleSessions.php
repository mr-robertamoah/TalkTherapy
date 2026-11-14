<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-34b: a signed-in session remembers a fingerprint of its account's password and two-factor state; when either
// changes elsewhere -- a new password, two-factor turned on or off, new recovery codes (which change the password hash,
// Auth::logoutOtherDevices) -- every other session of that account ends. The session that made the change takes the
// new fingerprint at the end of its own request (the actions update the signed-in model), so it stays signed in. Like
// Laravel's AuthenticateSession, plus the two-factor state, so turning it on needs no second password entry. A session
// from before this existed takes the current fingerprint on its next request.
class EndStaleSessions
{
    public const KEY = 'auth_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $request->hasSession() ? $guard->user() : null;

        if ($user instanceof User) {
            $stored = $request->session()->get(self::KEY);

            if ($stored === null) {
                $request->session()->put(self::KEY, self::fingerprint($user));
            } elseif (! hash_equals($stored, self::fingerprint($user))) {
                $guard->logoutCurrentDevice();
                $request->session()->flush();

                throw new AuthenticationException('Unauthenticated.', ['web']);
            }
        }

        $response = $next($request);

        if ($request->hasSession() && ($current = $guard->user()) instanceof User) {
            $request->session()->put(self::KEY, self::fingerprint($current));
            // SCRUM-422 (QA): Sanctum's own session check on the API (AuthenticateSession) keeps the password hash under
            // this key. A request here that rehashed it (Auth::logoutOtherDevices: a new email, new recovery codes)
            // would otherwise read as someone else's change on this session's next API call and sign it out too.
            $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $current->getAuthPassword());
        }

        return $response;
    }

    public static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->getAuthPassword().'|'.($user->two_factor_confirmed_at?->getTimestamp() ?? ''), (string) config('app.key'));
    }
}
