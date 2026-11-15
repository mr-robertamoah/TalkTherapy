<?php

namespace App\Support;

use App\Actions\Notification\PruneNotificationsAction;

// SCRUM-368: how long things are kept, in days, read from config on each call -- never copies that could drift from
// what the scheduled jobs actually do. SCRUM-50: shared by the privacy policy and "What we hold about you".
class RetentionPeriods
{
    public static function days(): array
    {
        return [
            'closedAccount' => config('user.closed_account_retention_days'),
            'counsellorProfile' => config('counsellor.deletion_grace_period_days'),
            'identityDocument' => config('identity_verification.document_retention_days'),
            'licenseRejected' => config('counsellor.license_rejected_retention_days'),
            'nationalId' => config('counsellor.national_id_retention_days'),
            'licenseAbandoned' => config('counsellor.license_abandoned_retention_days'),
            // SCRUM-33 (TT-4.4c): urgent help chats.
            'helpChat' => config('help.transcript_retention_days'),
            // SCRUM-39 (TT-5.4d): notifications (each person can change theirs).
            'notifications' => PruneNotificationsAction::defaultDays(),
        ];
    }
}
