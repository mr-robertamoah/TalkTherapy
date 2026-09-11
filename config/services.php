<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    // TT-3.1a/SCRUM-274: Daily.co's REST API for room creation/meeting tokens.
    'daily' => [
        'api_key' => env('DAILY_API_KEY'),
        'base_url' => env('DAILY_BASE_URL', 'https://api.daily.co/v1'),
        'domain' => env('DAILY_DOMAIN'),
    ],

    // TT-3.1a/SCRUM-274: Amazon Chime SDK Meetings -- deliberately separate credentials from the
    // 'ses' block above (a Chime-specific IAM principal, scoped to only chime:CreateMeeting/
    // CreateAttendee/DeleteMeeting, is safer than reusing whatever broader SES credentials this
    // app already has). media_region is the Chime meeting's own MediaRegion (where call media is
    // routed), independent of AWS_DEFAULT_REGION (which the SDK client itself authenticates
    // against) -- these are frequently, deliberately different in real deployments.
    'chime' => [
        'key' => env('CHIME_AWS_ACCESS_KEY_ID'),
        'secret' => env('CHIME_AWS_SECRET_ACCESS_KEY'),
        'region' => env('CHIME_AWS_REGION', 'us-east-1'),
        'media_region' => env('CHIME_MEDIA_REGION', 'us-east-1'),
    ],

];
