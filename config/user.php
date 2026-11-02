<?php

return [
    // SCRUM-367: a closed (soft-deleted) account's personal details are erased this many days after
    // it was closed (AnonymiseClosedAccountAction, scheduled daily in routes/console.php). The
    // privacy policy (/privacy) is given this value (routes/web.php), so the two never drift.
    // Never below 7: an empty or mistyped value must not erase accounts the day they close.
    'closed_account_retention_days' => max(7, (int) env('CLOSED_ACCOUNT_RETENTION_DAYS', 30)),
];
