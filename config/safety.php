<?php

// SCRUM-32: a counsellor's safety flag (recommending specialist or inpatient care) and the alerts it
// sends. Off by default (the user's decision): the routes, the admin menu item and the alerts stay
// hidden until the Ghana Act 846/843 legal read is done and SAFETY_FLAGS_ENABLED is switched on.
return [
    'enabled' => (bool) env('SAFETY_FLAGS_ENABLED', false),

    // The user-facing name, in one place so a legal rename is cheap (the user's decision, 2026-11-08).
    'label' => 'Safety flag: recommend specialist or inpatient care',

    // Flags a counsellor may raise in an hour (on top of the route's own throttle).
    'flags_per_hour' => (int) env('SAFETY_FLAGS_PER_HOUR', 10),

    // A counsellor may still flag this many days after a therapy, group or help chat ended.
    'flag_window_days_after_end' => (int) env('SAFETY_FLAG_WINDOW_DAYS', 14),

    // TT-4.3b: a flag nobody has acknowledged re-alerts the super administrators once, this many
    // minutes after it was raised (by urgency). A missing or invalid value never means "never": the
    // default is used and an error logged (SafetyFlag::realertMinutes).
    'realert_minutes' => [
        'IMMINENT' => env('SAFETY_REALERT_MINUTES_IMMINENT', 60),
        'URGENT' => env('SAFETY_REALERT_MINUTES_URGENT', 240),
        'NON_URGENT' => env('SAFETY_REALERT_MINUTES_NON_URGENT', 1440),
    ],

    // TT-4.3b: when no administrator who isn't involved can be told, this mailbox is (as well as a
    // critical log entry). Must be set before the feature is switched on in production.
    'fallback_mailbox' => env('SAFETY_FALLBACK_MAILBOX'),
];
