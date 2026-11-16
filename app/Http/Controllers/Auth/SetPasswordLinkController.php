<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

// SCRUM-455 (Google sign-in, SCRUM-112): "Set a password", for an account without one (made with Google) -- the usual
// password-reset link, sent to the account's own verified email; the reset page sets it. Never to an unverified address
// (whoever typed it may not own it), and never a way to change a password the account already has.
class SetPasswordLinkController extends Controller
{
    public const NO_VERIFIED_EMAIL = 'Add an email address to your account and confirm it first -- the link to set a password is sent there.';

    public const HAS_PASSWORD = 'Your account already has a password. You can change it below.';

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasPassword()) {
            return back(303)->withErrors(['password_link' => self::HAS_PASSWORD]);
        }

        if (! $user->email || ! $user->hasVerifiedEmail()) {
            return back(303)->withErrors(['password_link' => self::NO_VERIFIED_EMAIL]);
        }

        $status = Password::sendResetLink(['email' => $user->email]);

        return $status === Password::RESET_LINK_SENT
            ? back(303)->with('status', 'password-link-sent')
            : back(303)->withErrors(['password_link' => __($status)]);
    }
}
