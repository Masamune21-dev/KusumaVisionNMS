<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menyetel header Content-Security-Policy untuk respons HTML aplikasi.
 *
 * Memakai nonce per-request: Laravel Vite (@vite) dan Ziggy (@routes) ikut
 * menyematkan nonce yang sama, sehingga satu-satunya skrip inline (blok Ziggy)
 * lolos tanpa harus membuka script-src ke 'unsafe-inline' — jadi CSP tetap
 * memberi mitigasi XSS nyata. Style dibiarkan 'unsafe-inline' karena ApexCharts,
 * Leaflet, dan AOS menyuntik style inline saat runtime.
 *
 * Satu sumber CSP: sengaja di sisi aplikasi (bukan nginx) agar konsisten di
 * semua mode deploy (install.sh / Docker / server live) dan bisa memakai nonce.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        // Bangkitkan & simpan nonce sebelum view dirender, supaya tag Vite/Ziggy
        // memakai nonce yang identik dengan yang kita tulis ke header di bawah.
        Vite::useCspNonce();

        $response = $next($request);

        // Jangan paksakan CSP saat dev lokal (vite HMR @ localhost:5173 memakai
        // skrip inline + websocket yang akan diblokir), dan hanya untuk HTML.
        if (app()->isLocal() || ! $this->isHtml($response)) {
            return $response;
        }

        // Jika header sudah diset di lapisan lain (mis. nginx), jangan gandakan.
        if ($response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', $this->policy(Vite::cspNonce()));

        return $response;
    }

    protected function isHtml(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type', '');

        // Respons Inertia XHR ber-Content-Type application/json — lewati (bukan dokumen).
        return $type === '' || str_contains(strtolower($type), 'text/html');
    }

    protected function policy(?string $nonce): string
    {
        $script = "'self'".($nonce ? " 'nonce-{$nonce}'" : '');

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src {$script}",
            // ApexCharts/Leaflet/AOS menyuntik style inline; @font dari bunny.net.
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            // Tiles peta (Google/OSM) + marker data/blob.
            "img-src 'self' data: blob: https:",
            // API same-origin (axios) + telnet WebSocket (same-origin wss via /telnet-ws).
            "connect-src 'self' wss:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            'upgrade-insecure-requests',
        ]);
    }
}
