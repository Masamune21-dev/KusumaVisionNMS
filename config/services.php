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
        // Sampel MENTAH hanya dipakai grafik 24 jam; rentang 7 & 30 hari dilayani
        // ringkasan per jam (onu_rx_hourly). 3 hari, bukan 1, sebagai margin —
        // dan aman dipersingkat karena prune menolak jalan melewati jam yang
        // belum terangkum, jadi agregasi yang macet membuat tabel tumbuh, bukan
        // membuat riwayat hilang.
        'rx_sample_retention_days' => (int) env('SNMP_POLLER_RX_RETENTION_DAYS', 3),
        // Rentang terpanjang yang bisa diminta UI adalah 30 hari; 45 memberi
        // margin tanpa menumpuk baris yang tak pernah dibaca. Sebagai gambaran, 5.000 ONU:
        // tiap 30 hari retensi di sini berharga ~3,6 juta baris — retensi panjang
        // di tabel per jam justru bisa lebih besar dari tabel mentahnya.
        'rx_hourly_retention_days' => (int) env('SNMP_POLLER_RX_HOURLY_RETENTION_DAYS', 45),
    ],

    // Titik awal Peta ONU/ODP (web & aplikasi). "Wilayah utama" opsional: bila diisi, peta
    // membuka di sini selama pengguna punya pin/ODP dalam radiusnya; kalau tidak, peta membuka
    // di kelompok titik terpadat milik pengguna. Lihat OnuMapPayloadService::defaultCenter().
    'map' => [
        'home_lat' => env('MAP_HOME_LAT'),
        'home_lng' => env('MAP_HOME_LNG'),
        'home_zoom' => (int) env('MAP_HOME_ZOOM', 12),
        'home_radius_km' => (float) env('MAP_HOME_RADIUS_KM', 20),
    ],

    // Konversi foto ODP ke WebP. PHP di server ini tidak punya GD/Imagick, jadi
    // konversi memakai biner `cwebp` (paket `webp`). Kalau binernya tak ada, foto
    // tetap tersimpan dalam format aslinya (lihat App\Services\Odp\OdpPhotoService).
    'cwebp' => [
        'binary' => env('CWEBP_BINARY', 'cwebp'),
        'quality' => (int) env('CWEBP_QUALITY', 82),
        'max_dimension' => (int) env('CWEBP_MAX_DIMENSION', 1600),
        'process_timeout' => (int) env('CWEBP_PROCESS_TIMEOUT', 30),
    ],

];
