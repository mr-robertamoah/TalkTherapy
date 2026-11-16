<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\PasswordDoesNotContainIdentity;
use App\Support\PasswordRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    // SCRUM-455 (security review): the link works signed in ("Set a password") -- but only for the signed-in account.
    public const OTHER_ACCOUNT = 'This link is for a different account. Sign out first.';

    public const GOOGLE_STILL_CONNECTED = 'Google sign-in is still connected -- disconnect it from your profile if you didn\'t connect it.';

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'otherAccount' => $this->forOtherAccount($request, $request->email) ? self::OTHER_ACCOUNT : null,
        ]);
    }

    // Signed in, and the link's address isn't this account's: never reset (or sign out) on its behalf.
    private function forOtherAccount(Request $request, mixed $email): bool
    {
        $user = $request->user();

        return $user !== null && (! $user->email || mb_strtolower((string) $user->email) !== mb_strtolower((string) $email));
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            // Only what was typed (the email): checking the account the email belongs to would tell anyone without a
            // valid reset token whether a guessed username is that account's.
            'password' => PasswordRules::for(),
        ]);

        if ($this->forOtherAccount($request, $request->email)) {
            throw ValidationException::withMessages(['email' => self::OTHER_ACCOUNT]);
        }

        $googleStillConnected = false;

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request, &$googleStillConnected) {
                // SCRUM-35: the token is good, so the account can be checked too (its username) -- the reset token
                // stays usable if this refuses.
                Validator::make($request->only('password'), ['password' => [new PasswordDoesNotContainIdentity($user)]])->validate();

                // SCRUM-455 (security review): a reset of a password the account already had may be the owner taking it
                // back -- a Google account connected meanwhile stays (they're told; it isn't removed here).
                $googleStillConnected = $user->hasPassword() && filled($user->google_id);

                $user->forceFill([
                    'password' => Hash::make($request->password),
                    // SCRUM-35b: it has just met the whole policy, the breached-list check included.
                    'password_weak_reason' => null,
                    'password_checked_at' => config('auth.password_policy.uncompromised') ? now() : null,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PASSWORD_RESET) {
            // SCRUM-455: opened while signed in ("Set a password" on the profile): this browser signs in again with the
            // new password, as every other session of the account must (its password changed).
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->route('login')->with('status', __($status).($googleStillConnected ? ' '.self::GOOGLE_STILL_CONNECTED : ''));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
