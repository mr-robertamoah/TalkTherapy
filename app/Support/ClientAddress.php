<?php

namespace App\Support;

// SCRUM-400 (security review): one key for "this network address", for per-address limits and logs.
// An IPv6 address counts by its /64 (one household or device can rotate through a whole /64), and the
// key is an HMAC with the app key -- logs and cache keys stay linkable within one deployment, but an
// address can't be recovered from them (a plain hash of an IPv4 address can be, in seconds).
final class ClientAddress
{
    public static function key(?string $ip): string
    {
        $packed = $ip !== null ? @inet_pton($ip) : false;
        if ($packed !== false && strlen($packed) === 16) {
            $ip = bin2hex(substr($packed, 0, 8)).'::/64';
        }

        return substr(hash_hmac('sha256', (string) $ip, (string) config('app.key')), 0, 16);
    }
}
