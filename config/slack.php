<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Slack Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Slack integration including bot token, signing secret,
    | and app ID for authentication and event handling.
    |
    */

    'bot_token' => env('SLACK_BOT_TOKEN'),

    'signing_secret' => env('SLACK_SIGNING_SECRET'),

    'app_id' => env('SLACK_APP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Default Tenant ID
    |--------------------------------------------------------------------------
    |
    | Default tenant ID to use for single-tenant operations during development.
    |
    */
    'default_tenant_id' => env('TENANT_ID_DEFAULT', 1),

    /*
    |--------------------------------------------------------------------------
    | Event Verification
    |--------------------------------------------------------------------------
    |
    | Settings for Slack event verification including signature validation
    | and timestamp tolerance.
    |
    */
    'event_verification' => [
        'enabled' => env('SLACK_EVENT_VERIFICATION_ENABLED', true),
        'timestamp_tolerance' => env('SLACK_TIMESTAMP_TOLERANCE', 300), // 5 minutes in seconds
    ],
];
