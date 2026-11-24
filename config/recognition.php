<?php

// TT-7.11/SCRUM-464: counsellor recognition and the star rules behind it.
return [
    // How many counsellors may be recognised in a month.
    'per_month' => (int) env('RECOGNITION_PER_MONTH', 5),

    // SCRUM-471: the most stars a counsellor can earn from each rule in a UTC day, and from held sessions (help chats
    // included) with the same client. Defaults -- a super admin's platform setting (SettingsEnum) overrides each.
    'cap' => [
        'session' => 8,
        'help_chat' => 5,
        'discussion' => 2,
        'post' => 3,
        'session_per_client' => 1,
        // SCRUM-471 (security re-check): a group has no single client, so group-session stars are capped per group.
        'session_per_group' => 1,
        // SCRUM-472: stars from clients' likes and comments on a counsellor's posts -- at most this many per post (ever),
        // per counsellor per UTC day, and from one client to one counsellor per UTC day.
        'interaction_per_post' => 10,
        'interaction_per_day' => 20,
        'interaction_per_client' => 1,
    ],

    // SCRUM-471: a help chat earns a star only if it lasted at least this long.
    'help_chat_min_minutes' => 5,

    // SCRUM-472: interaction stars come only from accounts at least this many days old (their caps are in 'cap').
    'interaction_min_account_age_days' => 7,

    // Optional floor (a date, e.g. 2026-12-01) from which new-rule stars count. New-rule stars only exist from the
    // release anyway -- legacy stars have a NULL source and never count -- so this is an extra floor, not required.
    'stars_count_from' => env('RECOGNITION_STARS_COUNT_FROM'),
];
