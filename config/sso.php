<?php

/**
 * Konfigurasi klien SSO KusumaVision.
 *
 * App ini tidak lagi memeriksa password sendiri: seluruh pembuktian identitas
 * terjadi di `sso.kusumavision.net`, dan yang kembali ke sini hanyalah klaim
 * berisi siapa penggunanya dan role apa yang berlaku untuk app ini.
 */
return [

    'issuer' => rtrim(env('SSO_ISSUER', 'https://sso.kusumavision.net'), '/'),

    'client_id' => env('SSO_CLIENT_ID', 'nms'),

    'client_secret' => env('SSO_CLIENT_SECRET'),

    /**
     * Harus SAMA PERSIS dengan yang terdaftar di tabel `sso_clients` milik IdP.
     * Beda satu karakter pun akan ditolak — itu memang disengaja.
     */
    'redirect_uri' => env('SSO_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/sso/callback'),

    /**
     * Batas waktu panggilan back-channel ke /oauth/token. Dibuat pendek: ini
     * berada di jalur login, jadi IdP yang menggantung tidak boleh ikut
     * menggantungkan halaman login app ini.
     */
    'timeout' => (int) env('SSO_HTTP_TIMEOUT', 5),

    /**
     * Daftar dashboard untuk App Switcher. Yang menentukan bisa/tidaknya dibuka
     * tetap klaim `apps` dari IdP, bukan daftar ini.
     */
    'apps' => [
        'nms' => [
            'name' => 'NMS GPON',
            'url' => env('SSO_NMS_URL', 'https://nms.kusumavision.net').'/dashboard',
            'icon' => 'Cable',
        ],
        'mikrotik' => [
            'name' => 'MikroTik',
            'url' => env('SSO_MIKROTIK_URL', 'https://mikrotik.kusumavision.net').'/dashboard',
            'icon' => 'Router',
        ],
        'billing' => [
            'name' => 'Billing',
            'url' => env('SSO_BILLING_URL', 'https://billing.kusumavision.net').'/dashboard',
            'icon' => 'Wallet',
        ],
    ],

];
