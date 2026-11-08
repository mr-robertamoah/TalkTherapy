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
];
