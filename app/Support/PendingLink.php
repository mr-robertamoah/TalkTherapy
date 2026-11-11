<?php

namespace App\Support;

use Illuminate\Http\Request;

// SCRUM-415: a link opened while signed out (auth keeps it as the "intended" URL). Signing in goes back to
// it; signing up goes to the preferences page, which offers it instead. Only ever one of this app's own
// link pages, rebuilt from the uuid -- never the stored URL itself, so this can't redirect anywhere else.
class PendingLink
{
    private const PATTERN = '#^/links/([0-9a-fA-F-]{36})\z#';

    public static function continueUrl(Request $request): ?string
    {
        $intended = $request->hasSession() ? $request->session()->get('url.intended') : null;

        if (! is_string($intended)) {
            return null;
        }

        $parts = parse_url($intended);

        if (
            ! is_array($parts) ||
            (isset($parts['host']) && $parts['host'] !== $request->getHost()) ||
            ! preg_match(self::PATTERN, $parts['path'] ?? '', $matches)
        ) {
            return null;
        }

        return route('links.get', ['uuid' => $matches[1]], false);
    }

    // Opened now: nothing left to offer.
    public static function forget(Request $request): void
    {
        if ($request->hasSession() && self::continueUrl($request) === route('links.get', ['uuid' => $request->route('uuid')], false)) {
            $request->session()->forget('url.intended');
        }
    }
}
