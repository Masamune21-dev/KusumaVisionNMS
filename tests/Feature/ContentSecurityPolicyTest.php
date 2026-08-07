<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    public function test_http_response_omits_upgrade_insecure_requests(): void
    {
        // Deploy default install.sh melayani port 80 saja. Kalau direktif ini ikut
        // terkirim, browser menaikkan /build/assets/* ke https -> connection refused
        // -> aplikasi tampil sebagai halaman putih kosong.
        $response = $this->get('http://localhost/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function test_http_response_allows_plain_ws_for_telnet_terminal(): void
    {
        // Deploy Docker/LAN tanpa TLS memakai ws://; 'self' tak menjamin cocok untuk
        // skema ws: di semua browser -> terminal telnet diblokir CSP.
        $csp = (string) $this->get('http://localhost:8080/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self' wss: ws:", $csp);
    }

    public function test_https_response_omits_plain_ws(): void
    {
        $csp = (string) $this->get('https://localhost/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self' wss:;", $csp.';');
        $this->assertStringNotContainsString(' ws:', $csp);
    }

    public function test_https_response_keeps_upgrade_insecure_requests(): void
    {
        $response = $this->get('https://localhost/');

        $this->assertStringContainsString(
            'upgrade-insecure-requests',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_forwarded_proto_https_keeps_upgrade_insecure_requests(): void
    {
        // Di belakang Cloudflare "Flexible"/LB origin tetap http; trustProxies bikin
        // isSecure() ikut X-Forwarded-Proto sehingga halaman https tetap terproteksi.
        $response = $this->get('http://localhost/', ['X-Forwarded-Proto' => 'https']);

        $this->assertStringContainsString(
            'upgrade-insecure-requests',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }
}
