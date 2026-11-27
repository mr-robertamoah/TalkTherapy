<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

// SCRUM-479 (TT-9.3a): the interface language -- which ones this deployment offers (config/locales.php), the
// person's choice (users.locale for an account, the tt_locale cookie for anyone), and the one their browser asks for,
// which is only ever suggested, never applied (the user's decision: English unless someone picks otherwise).
class Locales
{
    public const COOKIE = 'tt_locale';

    public const DEFAULT = 'en';

    // A year: a choice lasts until it's changed. Minutes.
    private const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * @return list<string>
     */
    public static function enabled(): array
    {
        return config('locales.enabled');
    }

    public static function isEnabled(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::enabled(), true);
    }

    /**
     * What the shared props (and later the switchers) list: the enabled ones, each named in itself.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $code) => ['code' => $code, 'label' => config("locales.supported.{$code}.label")], self::enabled());
    }

    // The person's choice: their account's, else this browser's cookie -- only ever an enabled one.
    public static function chosen(?User $user, Request $request): ?string
    {
        if ($user && self::isEnabled($user->locale)) {
            return $user->locale;
        }

        $cookie = $request->cookie(self::COOKIE);

        return self::isEnabled($cookie) ? $cookie : null;
    }

    // The browser's first language (by Accept-Language quality), when it's an enabled one other than English --
    // matched by the app's code or its Intl tag (a browser set to Twi sends `ak`), region ignored. Nothing when English
    // (or anything not on offer) ranks first: English first, as the user decided.
    public static function suggested(Request $request): ?string
    {
        // Symfony's getLanguages(): highest quality first, `fr_FR` style.
        $first = $request->getLanguages()[0] ?? null;
        if ($first === null) {
            return null;
        }

        $primary = strtolower(explode('_', str_replace('-', '_', $first))[0]);
        foreach (self::enabled() as $code) {
            if ($code !== self::DEFAULT && in_array($primary, [$code, config("locales.supported.{$code}.intl")], true)) {
                return $code;
            }
        }

        return null;
    }

    // The tag for <html lang> and the browser's Intl APIs: the Intl tag where the app's code isn't one (Twi: `ak`).
    public static function htmlTag(string $locale): string
    {
        return config("locales.supported.{$locale}.intl") ?? $locale;
    }

    // This browser remembers the choice for a year (encrypted like every cookie, Lax, Secure as the session cookie is).
    public static function remember(string $locale): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $locale, self::COOKIE_MINUTES, sameSite: 'lax'));
    }

    // Signing out ends this browser's choice too: on a shared device the next person starts in English, and nothing
    // about the last one (a language can say something about someone) carries into another account.
    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    // On registering or signing in: an account with no choice yet takes this browser's; otherwise the account's wins,
    // and this browser is set to it.
    public static function carryOver(User $user, Request $request): void
    {
        if ($user->locale === null) {
            $cookie = $request->cookie(self::COOKIE);

            if (self::isEnabled($cookie)) {
                $user->forceFill(['locale' => $cookie])->save();
            }

            return;
        }

        if (self::isEnabled($user->locale)) {
            self::remember($user->locale);
        }
    }
}
