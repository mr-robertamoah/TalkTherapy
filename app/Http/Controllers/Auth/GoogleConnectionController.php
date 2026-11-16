<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConnectGoogleAccountAction;
use App\Actions\Auth\DisconnectGoogleAccountAction;
use App\DTOs\GoogleAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// SCRUM-455 (Google sign-in, SCRUM-112): connecting and disconnecting Google from the profile, for the signed-in
// person -- apart from GoogleSignInController (signing in, for guests), with its own callback URL
// (services.google.connect_redirect), so that callback only ever links, and the sign-in one never does. Both ask for
// the password first. Who may be connected is ConnectGoogleAccountAction's call.
class GoogleConnectionController extends Controller
{
    // The session key of a connect asked for from the profile -- the callback does nothing without it.
    public const INTENT = 'google_connect';

    // A connect started longer ago than this is not finished.
    private const INTENT_MINUTES = 10;

    public const FAILED = "We couldn't connect Google. Please try again.";

    public function connect(Request $request): Response
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']]);

        if (filled($request->user()->google_id)) {
            return back(303)->withErrors(['google' => ConnectGoogleAccountAction::ALREADY_CONNECTED]);
        }

        $request->session()->put(self::INTENT, ['user_id' => $request->user()->id, 'at' => now()->getTimestamp()]);

        // As for signing in: never stateless(), and PKCE. Google's page is another site, so a full-page visit.
        $google = Socialite::driver('google')
            ->redirectUrl((string) config('services.google.connect_redirect'))
            ->enablePKCE()
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();

        return Inertia::location($google->getTargetUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $intent = $request->session()->pull(self::INTENT);
        $started = is_array($intent) && ($intent['user_id'] ?? null) === $request->user()->id
            && now()->getTimestamp() - (int) ($intent['at'] ?? 0) <= self::INTENT_MINUTES * 60;

        // Not asked for from this session's profile (a link someone was sent), cancelled on Google's page, or Google
        // refused: nothing is read from Google, and its error text is never shown.
        if (! $started || $request->has('error')) {
            return $this->failed($request);
        }

        $google = $this->googleAccount();

        if (! $google) {
            return $this->failed($request);
        }

        $refusal = ConnectGoogleAccountAction::new()->execute($request->user(), $google);

        return $refusal
            ? $this->toProfile()->withErrors(['google' => $refusal])
            : $this->toProfile()->with('status', 'google-connected');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        // Before the password, which such an account doesn't have: what to do instead.
        if (! $request->user()->hasPassword()) {
            return back(303)->withErrors(['google' => DisconnectGoogleAccountAction::NEEDS_PASSWORD]);
        }

        $request->validate(['current_password' => ['required', 'string', 'current_password']]);

        $refusal = DisconnectGoogleAccountAction::new()->execute($request->user());

        return $refusal
            ? back(303)->withErrors(['google' => $refusal])
            : back(303)->with('status', 'google-disconnected');
    }

    private function googleAccount(): ?GoogleAccount
    {
        try {
            $user = Socialite::driver('google')->redirectUrl((string) config('services.google.connect_redirect'))->enablePKCE()->user();
        } catch (InvalidStateException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $sub = (string) $user->getId();

        return $sub === '' ? null : GoogleAccount::fromClaims($sub, $user->getEmail(), $user->getRaw()['email_verified'] ?? null);
    }

    private function toProfile(): RedirectResponse
    {
        return redirect()->to(route('profile.show').'#profile-google-id');
    }

    // Nothing of an unfinished attempt is left in the session to be used later.
    private function failed(Request $request): RedirectResponse
    {
        $request->session()->forget(['state', 'code_verifier']);

        return $this->toProfile()->withErrors(['google' => self::FAILED]);
    }
}
