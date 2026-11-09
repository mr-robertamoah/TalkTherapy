<?php

// SCRUM-39 (TT-5.4d, the user's decision): notifications in the notification center are deleted after a
// period -- this default, which each person can change in their profile (users.notification_retention_days,
// one of the options). An unread safety notice is never deleted. PruneNotificationsAction, daily; the
// privacy policy reads these values.
return [
    // Days. A number under PruneNotificationsAction::MINIMUM_DAYS (7), or not a number, skips the run with an
    // error logged -- a mistyped setting never deletes more than intended. Empty or 0 means the default, 90.
    'retention_days' => env('NOTIFICATIONS_RETENTION_DAYS') ?: 90,

    // What a person may choose instead of the default -- each at least the minimum (a shorter one would be
    // treated as the minimum).
    'retention_options' => [30, 90, 180, 365],
];
