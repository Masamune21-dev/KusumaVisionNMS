<?php

return [
    // Bind address for the WebSocket<->telnet proxy daemon (php artisan telnet:proxy).
    // Keep this bound to localhost in production and expose it via an nginx wss route.
    'proxy' => [
        'host' => env('TELNET_PROXY_HOST', '127.0.0.1'),
        'port' => (int) env('TELNET_PROXY_PORT', 6002),
    ],

    // Public WebSocket base URL the browser connects to. In production this is the
    // nginx-proxied wss endpoint (e.g. wss://nms.kusumavision.net/telnet-ws).
    // Leave null in local dev to derive ws://<request-host>:<proxy port>.
    'ws_url' => env('TELNET_PROXY_WS_URL'),

    // Seconds a connection ticket stays valid (time to open the WebSocket).
    'ticket_ttl' => (int) env('TELNET_PROXY_TICKET_TTL', 60),

    // Seconds to wait when dialling the OLT telnet port.
    'connect_timeout' => (int) env('TELNET_PROXY_CONNECT_TIMEOUT', 10),
];
