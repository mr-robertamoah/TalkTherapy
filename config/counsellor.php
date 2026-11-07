<?php

return [
    // SCRUM-134: a soft-deleted Counsellor row is permanently purged this many days after
    // deletion (see PurgeExpiredSoftDeletedCounsellorsAction, scheduled in
    // routes/console.php). Configurable so the grace period can be tuned without a deploy.
    'deletion_grace_period_days' => (int) env('COUNSELLOR_DELETION_GRACE_PERIOD_DAYS', 60),

    // SCRUM-338: how long a decided counsellor verification request's documents are kept (see
    // LicenseService::applyDocumentRetention(), scheduled daily in routes/console.php):
    //  - every document of a REJECTED request, this many days after the rejection;
    'license_rejected_retention_days' => (int) env('COUNSELLOR_LICENSE_REJECTED_RETENTION_DAYS', 30),
    //  - the national ID scan and number of an ACCEPTED request (the professional license is kept).
    'national_id_retention_days' => (int) env('COUNSELLOR_NATIONAL_ID_RETENTION_DAYS', 30),
    //  - SCRUM-362: every document of a license no verification request ever cited (an abandoned
    //    upload), this many days after it was uploaded.
    'license_abandoned_retention_days' => (int) env('COUNSELLOR_LICENSE_ABANDONED_RETENTION_DAYS', 30),

    // SCRUM-31: a platform verification lasts this many months from the day an administrator accepts
    // it (renewed early, from its current end). Counsellors already verified when this shipped got
    // this long from that day (the backfill migration). See App\Support\CounsellorVerification.
    'verification_validity_months' => (int) env('COUNSELLOR_VERIFICATION_VALIDITY_MONTHS', 12),

    // SCRUM-31: reminders this many days before a verification ends (the counsellor, and the admins
    // through their to-do count). The largest is also when the counsellor may re-verify early.
    // Kept as written (e.g. "30,7"); App\Support\CounsellorVerification::reminderDays() parses it.
    'verification_reminder_days' => env('COUNSELLOR_VERIFICATION_REMINDER_DAYS', '30,7'),
];
