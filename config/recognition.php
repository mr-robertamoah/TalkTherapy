<?php

// TT-7.11/SCRUM-464: counsellor recognition and the star rules behind it.
return [
    // How many counsellors may be recognised in a month.
    'per_month' => (int) env('RECOGNITION_PER_MONTH', 5),

    // Stars a counsellor can earn a day for publishing posts.
    'posts_per_day' => 3,

    // Interaction stars (from verified-email clients): at most this many per post, and per counsellor per day...
    'interaction_per_post' => 10,
    'interaction_per_day' => 20,
    // ...and only from accounts at least this many days old.
    'interaction_min_account_age_days' => 7,

    // Optional floor (a date, e.g. 2026-12-01) from which new-rule stars count. New-rule stars only exist from the
    // release anyway -- legacy stars have a NULL source and never count -- so this is an extra floor, not required.
    'stars_count_from' => env('RECOGNITION_STARS_COUNT_FROM'),
];
