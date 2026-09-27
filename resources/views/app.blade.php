<!DOCTYPE html>
{{--
    data-theme WAJIB selalu terisi nilai konkret (dark|light). Seluruh warna
    aplikasi adalah CSS custom property yang dideklarasikan pada selektor ini.
    color-scheme dipasang inline supaya scrollbar & kontrol native sudah benar
    sebelum stylesheet selesai diunduh.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $kvTheme ?? 'dark' }}" style="color-scheme: {{ $kvTheme ?? 'dark' }}">
    <head>
        {{--
            Elemen PERTAMA di <head>. Server sudah memasang tema untuk pilihan
            dark/light; skrip ini hanya menangani 'system' — satu-satunya kasus
            yang mustahil diketahui server — sebelum satu piksel pun tergambar.
            CSP melarang skrip inline tanpa nonce: tanpanya skrip ini diblokir
            diam-diam.
        --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            (function () {
                try {
                    if (@json($kvThemePref ?? 'dark') !== 'system') return;
                    var t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', t);
                    document.documentElement.style.colorScheme = t;
                } catch (e) { /* tema bawaan dari server tetap berlaku */ }
            })();
        </script>

        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Display timezone (per-deployment) for human-facing time labels; storage stays UTC -->
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            window.KV_DISPLAY_TZ = @json(\App\Support\DisplayTime::timezone());
            window.KV_DISPLAY_TZ_LABEL = @json(\App\Support\DisplayTime::label());
        </script>

        <!-- Scripts -->
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-canvas text-slate-100">
        @inertia
    </body>
</html>
