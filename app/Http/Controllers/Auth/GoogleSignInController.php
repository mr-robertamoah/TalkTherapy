<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BeginSignInAction;
use App\Actions\Auth\ResolveGoogleAccountAction;
use App\DTOs\GoogleAccount;
use App\Enums\GoogleAccountOutcomeEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

// SCRUM-453 (Google sign-in, SCRUM-112): "Continue with Google" for existing, connected accounts. Thin: who it is for
// is ResolveGoogleAccountAction's call; signing in (or the two-factor step) is BeginSignInAction's, as for a password.
// Each outcome is its own method, so new users (SCRUM-454) and connecting from the profile (SCRUM-455) add theirs.
class GoogleSignInController extends Controller
{
    public const FAILED = "We couldn't sign you in with Google. Please try again, or sign in with your username or email and password.";

    public function redirect(): SymfonyRedirectResponse
    {
        // Never stateless(): the state kept in the session is what ties the callback to this browser. PKCE as well, so
        // a code intercepted on the way back can't be exchanged without this session's verifier.
        return Socialite::driver('google')
            ->enablePKCE()
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        // Cancelled on Google's page, or Google refused: its error text is never shown.
        if ($request->has('error')) {
            return $this->failed($request);
        }

        $google = $this->googleAccount();

        if (! $google) {
            return $this->failed($request);
        }

        $resolution = ResolveGoogleAccountAction::new()->execute($google);

        return match ($resolution->outcome) {
            GoogleAccountOutcomeEnum::SignIn => $this->signIn($request, $resolution->user()),
            GoogleAccountOutcomeEnum::Refused => $this->refuse($resolution->refusal()),
        };
    }

    private function googleAccount(): ?GoogleAccount
    {
        try {
            $user = Socialite::driver('google')->enablePKCE()->user();
        } catch (InvalidStateException) {
            // An old or replayed callback, or one from another browser: expected, nothing to report.
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $sub = (string) $user->getId();

        return $sub === '' ? null : GoogleAccount::fromClaims($sub, $user->getEmail(), $user->getRaw()['email_verified'] ?? null);
    }

    // Never remembered (the user's decision: shared phones) -- a Google sign-in lasts for this browser session. A
    // suspension is enforced on the next request by EnsureAccountIsNotSuspended, as for a password sign-in.
    private function signIn(Request $request, User $user): RedirectResponse
    {
        return BeginSignInAction::new()->execute($request, $user, remember: false);
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }

    // Nothing of an unfinished attempt is left in the session to be used later.
    private function failed(Request $request): RedirectResponse
    {
        $request->session()->forget(['state', 'code_verifier']);

        return $this->refuse(self::FAILED);
    }
}
