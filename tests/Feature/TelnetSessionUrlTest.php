<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelnetSessionUrlTest extends TestCase
{
    use RefreshDatabase;

    private function olt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-C320-PATI',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_ws_url_keeps_non_standard_port(): void
    {
        // Deploy Docker mem-publish container :80 ke host :8080. Kalau port hilang,
        // browser menembak ws://localhost:80 -> "WebSocket error" walau daemon hidup.
        config(['telnet.ws_url' => '/telnet-ws']);

        $response = $this->actingAs($this->admin())
            ->post('http://localhost:8080/smartolt/'.$this->olt()->id.'/telnet/token');

        $response->assertOk();
        $this->assertStringStartsWith('ws://localhost:8080/telnet-ws?token=', $response->json('ws_url'));
    }

    public function test_ws_url_uses_wss_without_port_on_https(): void
    {
        config(['telnet.ws_url' => '/telnet-ws']);

        $response = $this->actingAs($this->admin())
            ->post('https://nms.kusumavision.net/smartolt/'.$this->olt()->id.'/telnet/token');

        $response->assertOk();
        $this->assertStringStartsWith('wss://nms.kusumavision.net/telnet-ws?token=', $response->json('ws_url'));
    }
}
