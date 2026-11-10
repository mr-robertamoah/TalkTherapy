<?php

namespace App\Support;

use Illuminate\Http\Request;

// SCRUM-138 (and SCRUM-395/400): which proxies may tell us a visitor's real address and scheme
// (X-Forwarded-For / -Proto), from TRUSTED_PROXIES. Only explicit addresses or CIDR ranges -- the
// proxy's own. Anything that would trust more than that is dropped: "*" or "**" (every connection),
// REMOTE_ADDR (whoever connects), PRIVATE_SUBNETS (any private address; its IPv4-mapped forms once had a
// bypass), ranges wider than /8 (IPv4) or /29 (IPv6), and IPv6 entries in the IPv4-mapped space. Behind a
// proxy with none of its addresses listed, everyone looks like the proxy -- see
// documentation/operations/production-checklist.md.
final class TrustedProxies
{
    public const MIN_V4_PREFIX = 8;

    public const MIN_V6_PREFIX = 29;

    // Only what a proxy must tell us: the visitor's address, port and scheme. Not X-Forwarded-Host or -Prefix:
    // links are built on APP_URL anyway (AppHost), and some proxies pass a client's own values through.
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB;

    /** @return list<string> entries in $value that fromEnv() drops -- to warn about (AppServiceProvider). */
    public static function ignored(?string $value): array
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $entry) => $entry !== ''));

        return array_values(array_diff($entries, self::fromEnv($value)));
    }

    /** @return list<string> */
    public static function fromEnv(?string $value): array
    {
        $entries = array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($entries, fn (string $entry) => self::isExplicit($entry)));
    }

    public static function isExplicit(string $entry): bool
    {
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        $isV4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = ! $isV4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $isV4 && ! $isV6) {
            return false;
        }
        // An IPv6 entry in the mapped/compatible space (first 80 bits zero: ::ffff:0:0/96, ::/16, ...) would match
        // IPv4 visitors seen through a dual-stack listener -- every one of them, at its widest.
        // IPv6 loopback (::1 alone) is fine: a proxy on the same host.
        $loopback = $isV6 && inet_pton($address) === inet_pton('::1') && in_array($prefix, [null, '128'], true);
        if ($isV6 && ! $loopback && str_starts_with((string) inet_pton($address), str_repeat("\0", 10))) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }

        // A range, but never one so wide it is close to "everyone": at least /8 (IPv4) or /29 (IPv6).
        // That still fits a VPC's 10.0.0.0/8 and every Cloudflare range (the widest are /13 and /29).
        return ctype_digit($prefix) && (int) $prefix >= ($isV4 ? self::MIN_V4_PREFIX : self::MIN_V6_PREFIX) && (int) $prefix <= ($isV4 ? 32 : 128);
    }
}
