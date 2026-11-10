<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-406: Inertia keeps each page's props in the browser's history; encrypted, and cleared when a session ends,
// the back button after signing out can't show chats or admin views. The browser can only encrypt in a secure
// context (https, or a localhost host): elsewhere Inertia v2 throws "Unable to encrypt history" and navigation
// stops working (security review, confirmed in the browser). So it is asked to only when it can.
final class InertiaHistory
{
    // config('inertia.history.encrypt') is the switch; this request must also be one the browser treats as secure.
    // Behind a TLS-terminating proxy, isSecure() needs TRUSTED_PROXIES (SCRUM-138) -- without it, no encryption.
    public static function encrypts(Request $request): bool
    {
        if (! config('inertia.history.encrypt')) {
            return false;
        }

        $host = strtolower($request->getHost());

        return $request->isSecure()
            || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost');
    }

    // A page Inertia rendered: the first full load (HTML) or a visit (X-Inertia JSON).
    public static function isPage(Response $response): bool
    {
        return $response->headers->has('X-Inertia')
            || str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
