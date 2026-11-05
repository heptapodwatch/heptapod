<?php

declare(strict_types=1);

return [

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

    // Local dev stacks only: a recorded account directory relative to the app root, e.g. tests/Fixtures/ohdear/real.
    'ohdear' => [
        'import_fixture' => env('OHDEAR_IMPORT_FIXTURE'),
        'import_fixture_failed_page' => env('OHDEAR_IMPORT_FIXTURE_FAILED_PAGE'),
        'import_fixture_failed_path' => env('OHDEAR_IMPORT_FIXTURE_FAILED_PATH'),
        'import_fixture_added_monitor' => env('OHDEAR_IMPORT_FIXTURE_ADDED_MONITOR'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
