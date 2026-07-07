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

    // ACS / TR069 default endpoint dipakai fitur "Aktifkan TR069 Massal" (ZTE).
    // Nilai asli TIDAK di-hardcode di sini (repo publik) — set lewat .env / Settings.
    'acs' => [
        'url' => env('ACS_URL', ''),
        'username' => env('ACS_USERNAME', ''),
        'password' => env('ACS_PASSWORD', ''),
    ],

    // Firebase Cloud Messaging — push alarm ke aplikasi Android. Dormant sampai
    // service-account JSON di-drop ke path ini (fitur tetap aman tanpa kredensial).
    'fcm' => [
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),
        'min_severity' => env('FCM_MIN_SEVERITY', 'major'),
    ],

    'snmp_poller' => [
        'driver' => env('SNMP_POLLER_DRIVER', 'php'),
        'binary' => env('SNMP_POLLER_BINARY', base_path('bin/kv-snmp-poller')),
        'request_timeout' => env('SNMP_POLLER_REQUEST_TIMEOUT', '10s'),
        'process_timeout' => (int) env('SNMP_POLLER_PROCESS_TIMEOUT', 300),
        'retries' => (int) env('SNMP_POLLER_RETRIES', 2),
        'walk_mode' => env('SNMP_POLLER_WALK_MODE', 'bulk'),
        'max_repetitions' => (int) env('SNMP_POLLER_MAX_REPETITIONS', 10),
        'rx_sample_retention_days' => (int) env('SNMP_POLLER_RX_RETENTION_DAYS', 90),
    ],

];
