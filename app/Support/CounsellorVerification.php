<?php

namespace App\Support;

use App\Models\Counsellor;
use App\Models\User;
use Carbon\CarbonInterface;

// SCRUM-31: how long a counsellor's platform verification lasts, when they're reminded, and when
// they may re-verify early (config/counsellor.php). Defined once here; everything else reads it.
final class CounsellorVerification
{
    public static function periodMonths(): int
    {
        return max(1, (int) config('counsellor.verification_validity_months', 12));
    }

    /** @return array<int, int> days before the end, largest first */
    public static function reminderDays(): array
    {
        $days = array_values(array_unique(array_filter((array) config('counsellor.verification_reminder_days', [30, 7]), fn ($day) => (int) $day > 0)));
        rsort($days);

        return array_map('intval', $days ?: [30, 7]);
    }

    // How long before the end a counsellor may re-verify (and admins count them as due).
    public static function renewalWindowDays(): int
    {
        return self::reminderDays()[0];
    }

    // Whether $counsellor may ask to be verified (again) now: never verified, ended, revoked, or
    // within the renewal window.
    public static function mayRequest(Counsellor $counsellor): bool
    {
        return $counsellor->isNotVerified()
            || $counsellor->verification_expires_at->lte(now()->addDays(self::renewalWindowDays()));
    }

    public static function isRenewalOpen(Counsellor $counsellor): bool
    {
        return $counsellor->isVerified() && self::mayRequest($counsellor);
    }

    // The end date an accepted verification sets now: the full period from the current end while it
    // is still current (renewing early loses nothing), otherwise from now.
    public static function nextExpiry(Counsellor $counsellor): CarbonInterface
    {
        $from = $counsellor->isVerified() ? $counsellor->verification_expires_at : now();

        return $from->copy()->addMonths(self::periodMonths());
    }

    // SCRUM-30: what a counsellor's resources say about their verification. Anyone (signed out too):
    // only the public state (a revocation reads as ended). The counsellor themselves and
    // administrators: the true state, since when, until when, and whether renewal is open. The
    // counsellor alone: whether a request of theirs is waiting (the one query, run only for them).
    public static function projection(Counsellor $counsellor, ?User $viewer = null): array
    {
        $state = $counsellor->verificationState();
        $isOwner = $viewer && (int) $counsellor->user_id === $viewer->id;

        if (! $isOwner && ! $viewer?->isAdmin()) {
            return ['state' => $state->publicValue()];
        }

        return [
            'state' => $state->value,
            'verifiedSince' => $counsellor->verified_at?->toJSON(),
            'expiresAt' => $counsellor->verified_at ? $counsellor->verification_expires_at?->toJSON() : null,
            'revokedAt' => $counsellor->verification_revoked_at?->toJSON(),
            'renewalOpen' => self::isRenewalOpen($counsellor),
            'mayRequest' => self::mayRequest($counsellor),
            ...($isOwner ? ['hasPendingRequest' => $counsellor->hasPendingCounsellorVerificationRequest()] : []),
        ];
    }
}
