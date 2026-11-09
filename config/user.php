<?php

return [
    // SCRUM-367: a closed (soft-deleted) account's personal details are erased this many days after
    // it was closed (AnonymiseClosedAccountAction, scheduled daily in routes/console.php). The
    // privacy policy (/privacy) is given this value (routes/web.php), so the two never drift.
    // Never below 7: an empty or mistyped value must not erase accounts the day they close.
    'closed_account_retention_days' => max(7, (int) env('CLOSED_ACCOUNT_RETENTION_DAYS', 30)),

    // SCRUM-400: accounts created from one network address in an hour. Only accounts actually created
    // count (a typo or a taken username doesn't). Generous enough for a group signing up together on a
    // shared network; never under 1.
    'registrations_per_hour_per_address' => max(1, (int) env('REGISTRATIONS_PER_HOUR_PER_ADDRESS', 10)),
];
