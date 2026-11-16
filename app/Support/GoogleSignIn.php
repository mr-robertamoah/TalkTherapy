<?php

namespace App\Support;

// SCRUM-453 (Google sign-in, SCRUM-112): one answer to "can Google sign-in be used here?" -- switched on
// (GOOGLE_SIGN_IN_ENABLED) and the Google client configured. Its routes are a 404 and the button hidden otherwise.
final class GoogleSignIn
{
    public static function available(): bool
    {
        return (bool) config('auth.google.enabled')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.redirect'));
    }
}
