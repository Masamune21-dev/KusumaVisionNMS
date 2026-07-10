<?php

namespace Tests\Unit;

use App\Http\Controllers\OnuMapController;
use App\Services\OnuInventoryService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Kunci gerbang anti-SSRF resolver link peta (OnuMapController::hostResolvesPublic):
 * tiap hop redirect harus me-resolve ke IP publik, menolak metadata cloud / host
 * internal. Pakai URL ber-IP-literal supaya deterministik (tanpa DNS).
 */
class OnuMapLinkResolverTest extends TestCase
{
    private function hostResolvesPublic(string $url): bool
    {
        $controller = new OnuMapController($this->createMock(OnuInventoryService::class));
        $method = new ReflectionMethod($controller, 'hostResolvesPublic');
        $method->setAccessible(true);

        return (bool) $method->invoke($controller, $url);
    }

    public function test_rejects_cloud_metadata_and_internal_hosts(): void
    {
        // Vektor SSRF klasik: metadata cloud, loopback, private, link-local, OLT internal.
        $this->assertFalse($this->hostResolvesPublic('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse($this->hostResolvesPublic('http://127.0.0.1/'));
        $this->assertFalse($this->hostResolvesPublic('http://10.1.2.3/'));
        $this->assertFalse($this->hostResolvesPublic('http://192.168.99.61/'));
        $this->assertFalse($this->hostResolvesPublic('http://172.27.10.105/'));
    }

    public function test_rejects_non_http_schemes_and_empty_host(): void
    {
        $this->assertFalse($this->hostResolvesPublic('file:///etc/passwd'));
        $this->assertFalse($this->hostResolvesPublic('gopher://8.8.8.8/'));
        $this->assertFalse($this->hostResolvesPublic('not a url'));
    }

    public function test_allows_public_ip_literal_hosts(): void
    {
        $this->assertTrue($this->hostResolvesPublic('https://8.8.8.8/'));
        $this->assertTrue($this->hostResolvesPublic('https://142.250.4.100/maps'));
    }
}
