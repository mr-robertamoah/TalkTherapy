<?php

namespace App\Support;

use App\DTOs\GoogleAccount;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

// SCRUM-453 (Google sign-in, SCRUM-112): one answer to "can Google sign-in be used here?" -- switched on
// (GOOGLE_SIGN_IN_ENABLED) and the Google client configured. Its routes are a 404 and the button hidden otherwise.
// SCRUM-454: and the one way to talk to Google (Socialite), for signing in here and connecting from the profile
// (SCRUM-455, with its own callback URL), so both ask the same and read the answer the same way.
final class GoogleSignIn
{
    public static function available(): bool
    {
        return (bool) config('auth.google.enabled')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.redirect'));
    }

    // To Google's sign-in page. Never stateless(): the state kept in the session is what ties the callback to this
    // browser. PKCE as well, so a code intercepted on the way back can't be exchanged without this session's verifier.
    public static function redirect(?string $redirectUrl = null): RedirectResponse
    {
        return self::driver($redirectUrl)
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    // Who Google says came back, or null if the answer can't be read (Google's own error text is never passed on).
    public static function account(?string $redirectUrl = null): ?GoogleAccount
    {
        try {
            $user = self::driver($redirectUrl)->user();
        } catch (InvalidStateException) {
            // An old or replayed callback, or one from another browser: expected, nothing to report.
            return null;
        } catch (Throwable $e) {
            // Possibly a misconfigured client: reported, but never shown.
            report($e);

            return null;
        }

        $sub = (string) $user->getId();
        $raw = $user->getRaw();

        return $sub === '' ? null : GoogleAccount::fromClaims($sub, $user->getEmail(), $raw['email_verified'] ?? null, $raw['hd'] ?? null);
    }

    // Nothing of an unfinished attempt is left in the session to be replayed.
    public static function forgetState(Request $request): void
    {
        $request->session()->forget(['state', 'code_verifier']);
    }

    private static function driver(?string $redirectUrl): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('google');
        $driver->enablePKCE();

        return $redirectUrl === null ? $driver : $driver->redirectUrl($redirectUrl);
    }

    // SCRUM-455: and connecting it from the profile -- its own callback URL (GOOGLE_CONNECT_REDIRECT_URI) set as well.
    public static function connectable(): bool
    {
        return self::available() && filled(config('services.google.connect_redirect'));
    }
}
