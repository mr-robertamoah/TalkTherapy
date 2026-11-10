<?php

// SCRUM-33: the "I need help now" quick help chat.
return [
    // An emergency number is due a fresh check this many months after it was last verified (it is still
    // shown meanwhile; the admin page flags it).
    'number_review_months' => (int) env('HELP_NUMBER_REVIEW_MONTHS', 6),

    // Where a person's own country has no verified number (or they have none), the dialog opens on this
    // country (ISO alpha-2). Ghana, the platform's home market.
    'default_country' => env('HELP_DEFAULT_COUNTRY', 'GH'),

    // How long a counsellor's "available for urgent help" lasts once switched on (hours).
    'availability_hours' => (int) env('HELP_AVAILABILITY_HOURS', 4),

    // TT-4.4b: administrators are alerted when a request has waited this long, and again later.
    'first_alert_seconds' => (int) env('HELP_FIRST_ALERT_SECONDS', 60),
    'second_alert_seconds' => (int) env('HELP_SECOND_ALERT_SECONDS', 180),

    // A help request nobody accepts is closed after this many minutes.
    'expire_after_minutes' => (int) env('HELP_EXPIRE_AFTER_MINUTES', 10),

    // A connected help chat ends on its own after this many minutes.
    'session_minutes' => (int) env('HELP_SESSION_MINUTES', 60),

    // Starting requests: per person, per hour (a suspended account gets the stricter one).
    'requests_per_hour' => (int) env('HELP_REQUESTS_PER_HOUR', 3),
    'suspended_requests_per_hour' => (int) env('HELP_SUSPENDED_REQUESTS_PER_HOUR', 1),
    // And from one network address, whoever is signed in.
    'requests_per_hour_per_address' => (int) env('HELP_REQUESTS_PER_HOUR_PER_ADDRESS', 10),
    // SCRUM-395 (the user's decision): an account under this many hours old, or without a verified email,
    // gets this stricter one (against throwaway accounts ringing everyone).
    'new_account_hours' => (int) env('HELP_NEW_ACCOUNT_HOURS', 24),
    'new_account_requests_per_hour' => (int) env('HELP_NEW_ACCOUNT_REQUESTS_PER_HOUR', 1),

    // SCRUM-395 (the user's decision): at most this many requests ring the on-call counsellors in any one
    // minute, platform-wide. Past it a request is still made and shown to administrators (who are alerted
    // and can ring again) -- only the automatic ring is skipped.
    'rings_per_minute' => (int) env('HELP_RINGS_PER_MINUTE', 30),

    // SCRUM-395: the kill switch. Off, nobody can start a new request (the dialog still shows the emergency
    // numbers, and says so); requests already made carry on.
    'enabled' => filter_var(env('HELP_ENABLED', true), FILTER_VALIDATE_BOOL),

    // SCRUM-399: web push to on-call counsellors' devices, so a ring reaches them with the app closed. Off
    // until switched on (it needs the VAPID keys -- `php artisan push:vapid-keys` -- and the `urgent` queue).
    // Keys are made once per environment and kept: changing them silently ends every subscription.
    'push' => [
        'enabled' => filter_var(env('HELP_PUSH_ENABLED', false), FILTER_VALIDATE_BOOL),
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
        'vapid_subject' => env('VAPID_SUBJECT', 'mailto:support@talktherapy.local'),
        // Seconds a push may wait at the push service for a device: a ring is pointless once it's stale.
        'ttl' => (int) env('HELP_PUSH_TTL', 120),
        'devices_per_user' => 10,
        // The push services a device may name (https only): the server posts to a device's endpoint, so anything
        // else would let a counsellor point the queue at an internal address. "*." also matches subdomains.
        'endpoint_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('HELP_PUSH_ENDPOINT_HOSTS',
            'fcm.googleapis.com,android.googleapis.com,*.push.services.mozilla.com,*.push.apple.com,*.notify.windows.com'))))),
    ],

    // TT-4.4c: a help chat's messages, and the person's note, are deleted this many days after it
    // ended (the user's decision: one year). The request itself stays, without them. Under 30 (or not a
    // number) and the nightly run is skipped with an error logged -- never a shorter retention.
    'transcript_retention_days' => env('HELP_TRANSCRIPT_RETENTION_DAYS') ?: 365,
];
