<?php

return [
    // SCRUM-134: a soft-deleted Counsellor row is permanently purged this many days after
    // deletion (see AppService::purgeExpiredSoftDeletedCounsellors(), scheduled in
    // routes/console.php). Configurable so the grace period can be tuned without a deploy.
    'deletion_grace_period_days' => (int) env('COUNSELLOR_DELETION_GRACE_PERIOD_DAYS', 60),
];
