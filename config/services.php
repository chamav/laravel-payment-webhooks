<?php

return [

    'demo_pay' => [
        'webhook_secret' => env('WEBHOOK_SECRET', ''),
        'webhook_url' => env('DEMO_PAY_WEBHOOK_URL', 'http://app:8080/api/webhooks/demo-pay'),
        'sender_attempts' => env('DEMO_PAY_SENDER_ATTEMPTS', 3),
        // Capped exponential backoff: 1..2000 ms initially, capped at 2000 ms between attempts.
        'sender_backoff_ms' => env('DEMO_PAY_SENDER_BACKOFF_MS', 250),
        // 1..60 seconds per attempt; connect timeout is min(2, timeout).
        'sender_timeout_seconds' => env('DEMO_PAY_SENDER_TIMEOUT_SECONDS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
