<?php

return [
    // SCRUM-134: a soft-deleted Counsellor row is permanently purged this many days after
    // deletion (see AppService::purgeExpiredSoftDeletedCounsellors(), scheduled in
    // routes/console.php). Configurable so the grace period can be tuned without a deploy.
    'deletion_grace_period_days' => (int) env('COUNSELLOR_DELETION_GRACE_PERIOD_DAYS', 60),

    // SCRUM-338: how long a decided counsellor verification request's documents are kept (see
    // LicenseService::applyDocumentRetention(), scheduled daily in routes/console.php):
    //  - every document of a REJECTED request, this many days after the rejection;
    'license_rejected_retention_days' => (int) env('COUNSELLOR_LICENSE_REJECTED_RETENTION_DAYS', 30),
    //  - the national ID scan and number of an ACCEPTED request (the professional license is kept).
    'national_id_retention_days' => (int) env('COUNSELLOR_NATIONAL_ID_RETENTION_DAYS', 30),
];
