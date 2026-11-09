<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\URL;

// SCRUM-397: once deployed, the app answers only for its own host, and builds every absolute link (emails,
// redirects, notification-center links) on APP_URL -- never on a request's Host header, which a client
// chooses. Both halves follow Laravel's TrustHosts rule: on everywhere except APP_ENV local and testing (so a
// deployed environment must never be called "testing"). See documentation/operations/production-checklist.md.
final class AppHost
{
    // Exact hosts, as patterns: APP_URL's host, plus any in TRUSTED_HOSTS (comma-separated, e.g. a second
    // domain). No wildcard subdomains.
    /** @return list<string> */
    public static function trustedPatterns(): array
    {
        $hosts = array_filter(array_map(self::bareHost(...), [
            (string) config('app.url'),
            ...explode(',', (string) config('app.trusted_hosts')),
        ]));

        return array_values(array_unique(array_map(fn (string $host) => '^'.preg_quote($host, '/').'$', $hosts)));
    }

    // Links on APP_URL once deployed (https when APP_URL is).
    public static function enforceLinks(Application $app): void
    {
        $url = (string) config('app.url');
        if (! self::applies($app) || $url === '') {
            return;
        }

        URL::forceRootUrl($url);
        if (str_starts_with($url, 'https://')) {
            URL::forceScheme('https');
        }
    }

    // The same rule as Laravel's TrustHosts::shouldSpecifyTrustedHosts() (review: one rule for both halves).
    public static function applies(Application $app): bool
    {
        return ! $app->environment('local') && ! $app->runningUnitTests();
    }

    // "https://Example.com:443/x" or "example.com:443" -> "example.com" (review: an entry written with a scheme
    // or a port used to never match).
    private static function bareHost(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        $host = parse_url(str_contains($value, '://') ? $value : "http://{$value}", PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
