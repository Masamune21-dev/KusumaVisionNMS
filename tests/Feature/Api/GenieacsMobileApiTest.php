<?php

namespace Tests\Feature\Api;

use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endpoint GenieACS untuk aplikasi Android.
 *
 * Logikanya dipakai bersama halaman web, jadi yang diuji di sini khusus hal
 * yang bisa menyimpang antara kedua kanal: penanda ter-ACS ikut pada detail
 * ONU, dan aksi tulis WiFi benar-benar tertutup bagi peran non-tulis.
 */
class GenieacsMobileApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI-MOBILE',
            'vendor' => 'ZTE C320',
            'ip' => '10.7.7.7',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'ZTE C320'],
                // Snapshot sungguhan menyertakan slot & port pada SETIAP entri ONU
                // (ditulis scanner); `normalize()` memakainya untuk menyusun kunci
                // lookup ODP/ACS. Fixture harus meniru itu.
                'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'count' => 1, 'onus' => [
                    ['onu_id' => 1, 'slot' => 1, 'port' => 1, 'online' => true, 'serial_number' => 'CDTCAF0012E6'],
                ]]],
            ],
        ]);
    }

    private function linkDevice(SnmpOlt $olt): void
    {
        GenieacsDeviceMap::create([
            'device_id' => 'dev-mobile',
            'serial_number' => 'CDTCAF0012E6',
            'product_class' => 'FD512XW-R460',
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
            'match_method' => GenieacsDeviceMap::METHOD_SERIAL,
            'last_inform_at' => now(),
        ]);
    }

    public function test_onu_detail_carries_the_acs_badge(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/olts/{$olt->id}/onus/1/1/1")
            ->assertOk()
            ->assertJsonPath('data.acs.device_id', 'dev-mobile')
            ->assertJsonPath('data.acs.online', true);
    }

    public function test_onu_detail_reports_null_when_not_linked(): void
    {
        $olt = $this->makeOlt();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/olts/{$olt->id}/onus/1/1/1")
            ->assertOk()
            ->assertJsonPath('data.acs', null);
    }

    public function test_connected_devices_endpoint_returns_hosts(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        Http::fake(['*/devices*' => Http::response([[
            '_id' => 'dev-mobile',
            '_deviceId' => ['_SerialNumber' => 'CDTCAF0012E6'],
            'InternetGatewayDevice' => ['LANDevice' => ['1' => [
                'Hosts' => ['Host' => ['1' => [
                    'HostName' => ['_value' => 'HP-Teknisi'],
                    'IPAddress' => ['_value' => '192.168.1.20'],
                    'MACAddress' => ['_value' => 'AA:BB:CC:DD:EE:10'],
                    'InterfaceType' => ['_value' => '802.11'],
                    'Active' => ['_value' => true],
                ]]],
            ]]],
        ]], 200)]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/olts/{$olt->id}/onus/1/1/1/acs-clients")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('active_count', 1)
            ->assertJsonPath('hosts.0.hostname', 'HP-Teknisi');
    }

    public function test_wifi_update_is_closed_to_read_only_roles(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);

        // `demo` satu-satunya peran sah di luar daftar tulis.
        Sanctum::actingAs(User::factory()->create(['role' => 'demo']));

        $response = $this->postJson("/api/v1/olts/{$olt->id}/onus/1/1/1/acs-wifi", [
            'ssid' => 'NAKAL',
            'password' => 'rahasia12345',
            'wlan_index' => 1,
        ]);

        $this->assertTrue($response->status() >= 400, 'Peran baca-saja tidak boleh bisa mengubah WiFi pelanggan.');
        Http::assertNothingSent();
    }

    public function test_wifi_update_writes_through_the_same_service_as_web(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        Http::fake([
            '*/tasks*' => Http::response(['name' => 'setParameterValues'], 200),
            '*/devices*' => Http::response([[
                '_id' => 'dev-mobile',
                '_deviceId' => ['_SerialNumber' => 'CDTCAF0012E6'],
                'InternetGatewayDevice' => ['LANDevice' => ['1' => ['WLANConfiguration' => ['1' => [
                    'SSID' => ['_value' => 'LAMA'],
                    'BeaconType' => ['_value' => '11i'],
                    'X_CMS_KeyPassphrase' => ['_value' => 'lama1234'],
                ]]]]],
            ]], 200),
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/olts/{$olt->id}/onus/1/1/1/acs-wifi", [
            'ssid' => 'KUSUMANET-HP',
            'password' => 'rahasia12345',
            'wlan_index' => 1,
        ])->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/tasks')
            && str_contains(json_encode($request->data()), 'KUSUMANET-HP'));

        $audit = \App\Models\AuditLog::query()->latest('id')->first();
        $this->assertSame('genieacs.wifi.updated', $audit->event);
        $this->assertSame('mobile', $audit->properties['channel'] ?? null);
    }
}
