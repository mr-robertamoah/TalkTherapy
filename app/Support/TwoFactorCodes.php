<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-34: the authenticator-app maths in one place -- a new secret, its QR code, recovery codes and their hashes.
// Recovery codes are random (~50 bits each), so a keyed SHA-256 (HMAC with the app key) is enough: bcrypt would only
// slow each sign-in that uses one, and the key means a leaked database alone can't be searched for them (security review).
class TwoFactorCodes
{
    public static function google2fa(): Google2FA
    {
        $google2fa = new Google2FA;
        $google2fa->setWindow(config('auth.two_factor.window'));

        return $google2fa;
    }

    public static function newSecret(): string
    {
        return self::google2fa()->generateSecretKey(32);
    }

    // The QR an authenticator app scans, as an SVG -- labelled with the app and the username, never the email.
    public static function qrSvg(string $secret, string $label): string
    {
        $url = self::google2fa()->getQRCodeUrl(config('app.name'), $label, $secret);

        return (new Writer(new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd)))->writeString($url);
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [the codes to show once, their hashes to keep]
     */
    public static function newRecoveryCodes(): array
    {
        $codes = collect(range(1, config('auth.two_factor.recovery_codes')))
            ->map(fn () => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)))
            ->all();

        return [$codes, array_map(fn (string $code) => self::hash($code), $codes)];
    }

    public static function hash(string $code, ?string $key = null): string
    {
        return hash_hmac('sha256', self::normalise($code), $key ?? (string) config('app.key'));
    }

    // The hashes $code may be stored under: with the app key, and with each previous one (APP_PREVIOUS_KEYS), so
    // changing the key doesn't stop recovery codes made before it from working.
    public static function candidateHashes(string $code): array
    {
        return array_map(fn (string $key) => self::hash($code, $key), [(string) config('app.key'), ...array_filter((array) config('app.previous_keys'))]);
    }

    // What was typed, without spaces, dashes or case.
    public static function normalise(string $code): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]/i', '', $code));
    }
}
