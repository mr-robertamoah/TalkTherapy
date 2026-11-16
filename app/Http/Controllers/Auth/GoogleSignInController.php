<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BeginSignInAction;
use App\Actions\Auth\ResolveGoogleAccountAction;
use App\DTOs\GoogleAccount;
use App\Enums\GoogleAccountOutcomeEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleSignIn;
use App\Support\PendingGoogleSignUp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

// SCRUM-453 (Google sign-in, SCRUM-112): "Continue with Google" for existing, connected accounts. Thin: who it is for
// is ResolveGoogleAccountAction's call; signing in (or the two-factor step) is BeginSignInAction's, as for a password.
// Each outcome is its own method, so new users (SCRUM-454) and connecting from the profile (SCRUM-455) add theirs.
class GoogleSignInController extends Controller
{
    public const FAILED = "We couldn't sign you in with Google. Please try again, or sign in with your username or email and password.";

    // State and PKCE, the scopes and the account chooser: GoogleSignIn (shared with connecting from the profile).
    public function redirect(): SymfonyRedirectResponse
    {
        return GoogleSignIn::redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        // Cancelled on Google's page, or Google refused: its error text is never shown.
        if ($request->has('error')) {
            return $this->failed($request);
        }

        $google = GoogleSignIn::account();

        if (! $google) {
            return $this->failed($request);
        }

        $resolution = ResolveGoogleAccountAction::new()->execute($google);

        return match ($resolution->outcome) {
            GoogleAccountOutcomeEnum::SignIn => $this->signIn($request, $resolution->user()),
            GoogleAccountOutcomeEnum::StartSignUp => $this->startSignUp($request, $google),
            GoogleAccountOutcomeEnum::Refused => $this->refuse($resolution->refusal()),
        };
    }

    // Never remembered (the user's decision: shared phones) -- a Google sign-in lasts for this browser session. A
    // suspension is enforced on the next request by EnsureAccountIsNotSuspended, as for a password sign-in.
    private function signIn(Request $request, User $user): RedirectResponse
    {
        return BeginSignInAction::new()->execute($request, $user, remember: false);
    }

    // SCRUM-454: connected to nothing -- a new account, made once "Finish setting up" is sent (GoogleSignUpController).
    private function startSignUp(Request $request, GoogleAccount $google): RedirectResponse
    {
        PendingGoogleSignUp::begin($request, $google);

        return redirect()->route('auth.google.finish');
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }

    // Nothing of an unfinished attempt is left in the session to be used later.
    private function failed(Request $request): RedirectResponse
    {
        GoogleSignIn::forgetState($request);

        return $this->refuse(self::FAILED);
    }
}
