<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Alamat reverse proxy yang header X-Forwarded-* (IP klien, skema https)
    | boleh dipercaya. Dibaca middleware TrustProxies bawaan Laravel.
    |
    | Bawaan hanya localhost (nginx di host yang sama). Isi daftar IP/CIDR
    | dipisah koma bila aplikasi berada di belakang proxy lain — mis. rentang IP
    | Cloudflare, atau "*" bila memang tidak ada jalur lain ke origin.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

];
