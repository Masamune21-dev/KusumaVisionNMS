<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Display timezone (per-deployment) for human-facing time labels; storage stays UTC -->
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            window.KV_DISPLAY_TZ = @json(config('app.display_timezone', 'Asia/Jakarta'));
            window.KV_DISPLAY_TZ_LABEL = @json(config('app.display_timezone_label', 'WIB'));
        </script>

        <!-- Scripts -->
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
