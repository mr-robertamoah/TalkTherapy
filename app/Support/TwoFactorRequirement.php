<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-34d: two-factor sign-in is required for platform administrators from a date set by the deployment
// (TWO_FACTOR_ADMINS_REQUIRED_FROM). Before it, an administrator without it sees a reminder; from it, they can reach
// only their own account until it's set up (RequireTwoFactorForAdmins) -- never a lockout (they stay signed in, and the
// profile, setup and signing out always work). Nothing applies while the date is unset or setup is switched off
// (TWO_FACTOR_ENABLED=false would otherwise send them to a setup that isn't there).
class TwoFactorRequirement
{
    // The date it starts (the start of that day, in the app's timezone), or null when it's unset or unreadable. Only
    // YYYY-MM-DD (ops review): a looser parse took "false" as today and "tomorrow" as a day that never comes.
    public static function startsAt(): ?CarbonImmutable
    {
        $value = config('auth.two_factor.admins_required_from');

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        try {
            $date = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone')) : null;
        } catch (Throwable) {
            $date = null;
        }

        if (! $date || $date->toDateString() !== $value) {
            // Once per request: the middleware and the shared prop both ask.
            once(fn () => Log::warning('TWO_FACTOR_ADMINS_REQUIRED_FROM is not a date (YYYY-MM-DD); two-factor sign-in is not required for administrators.', ['value' => $value]));

            return null;
        }

        return $date;
    }

    // ['from' => 'Y-m-d', 'required' => bool] for an administrator who still has to set it up, otherwise null.
    public static function for(?User $user): ?array
    {
        // The cheap checks first: most requests are guests or not administrators.
        if (! $user || ! config('auth.two_factor.enabled') || ! $user->isAdmin() || $user->hasTwoFactorEnabled()) {
            return null;
        }

        $startsAt = self::startsAt();

        if (! $startsAt) {
            return null;
        }

        return ['from' => $startsAt->toDateString(), 'required' => ! $startsAt->isFuture()];
    }

    // Whether this person has to set it up before going on.
    public static function blocks(?User $user): bool
    {
        return (bool) (self::for($user)['required'] ?? false);
    }
}
