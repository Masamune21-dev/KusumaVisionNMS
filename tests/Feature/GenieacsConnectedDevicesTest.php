<?php

namespace Tests\Feature;

use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Panel "perangkat terhubung" pada tabel ONU.
 *
 * Endpoint ini MEMANGGIL NBI, jadi yang dijaga di sini: hanya dipanggil untuk
 * ONU yang memang berpasangan, hasilnya di-cache, dan kegagalan dibedakan
 * (belum berpasangan vs ACS tak terjangkau) supaya pesan di layar teknisi
 * tidak menyesatkan.
 */
class GenieacsConnectedDevicesTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI-ACS',
            'vendor' => 'ZTE C320',
            'ip' => '10.9.9.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'ZTE C320'],
                'port_onus' => [
                    '1_1' => ['slot' => 1, 'port' => 1, 'count' => 1, 'onus' => [
                        ['onu_id' => 1, 'online' => true, 'serial_number' => 'CDTCAF0012E6'],
                    ]],
                ],
            ],
        ]);
    }

    private function linkDevice(SnmpOlt $olt): GenieacsDeviceMap
    {
        return GenieacsDeviceMap::create([
            'device_id' => 'dev-klien',
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

    /**
     * Bentuk dokumen device GenieACS: tiap daun dibungkus `_value`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deviceDoc(): array
    {
        return [[
            '_id' => 'dev-klien',
            '_deviceId' => ['_SerialNumber' => 'CDTCAF0012E6'],
            'InternetGatewayDevice' => [
                'LANDevice' => ['1' => [
                    'Hosts' => ['Host' => [
                        '1' => [
                            'HostName' => ['_value' => 'Laptop-Budi'],
                            'IPAddress' => ['_value' => '192.168.1.10'],
                            'MACAddress' => ['_value' => 'AA:BB:CC:DD:EE:01'],
                            'InterfaceType' => ['_value' => '802.11'],
                        ],
                        '2' => [
                            'HostName' => ['_value' => 'TV-Ruang-Tamu'],
                            'IPAddress' => ['_value' => '192.168.1.11'],
                            'MACAddress' => ['_value' => 'AA:BB:CC:DD:EE:02'],
                            'InterfaceType' => ['_value' => 'Ethernet'],
                        ],
                    ]],
                ]],
            ],
        ]];
    }

    public function test_returns_connected_hosts_for_a_linked_onu(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        Http::fake(['*/devices*' => Http::response($this->deviceDoc(), 200)]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->getJson(route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('device_id', 'dev-klien')
            ->assertJsonCount(2, 'hosts');

        $hosts = $response->json('hosts');
        $this->assertSame('Laptop-Budi', $hosts[0]['hostname']);
        $this->assertSame('192.168.1.10', $hosts[0]['ip_address']);
    }

    /**
     * Tabel `Hosts.Host` milik ONU adalah tabel sewa DHCP yang menumpuk — pada
     * satu unit nyata berisi 64 entri padahal hanya 3 yang benar-benar
     * tersambung. Yang membedakan: tabel asosiasi WiFi (daftar radio).
     */
    public function test_marks_devices_present_in_the_wifi_association_table_as_active(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $doc = $this->deviceDoc();
        // Hanya MAC pertama yang sedang ter-asosiasi di radio WiFi.
        $doc[0]['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'] = ['1' => [
            'SSID' => ['_value' => 'KUSUMANET'],
            'AssociatedDevice' => ['1' => [
                'AssociatedDeviceMACAddress' => ['_value' => 'AA:BB:CC:DD:EE:01'],
            ]],
        ]];

        Http::fake(['*/devices*' => Http::response($doc, 200)]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]))
            ->assertOk()
            ->assertJsonPath('active_count', 1)
            ->assertJsonPath('unknown_count', 1)
            ->assertJsonPath('total_count', 2);

        $hosts = collect($response->json('hosts'))->keyBy('hostname');

        $this->assertTrue($hosts['Laptop-Budi']['active']);
        $this->assertSame('wifi', $hosts['Laptop-Budi']['active_source']);

        // Perangkat berkabel tak bisa dipastikan selama ONU tidak melaporkan
        // `Active`; ditandai "tidak diketahui", BUKAN dipaksa jadi tidak aktif.
        $this->assertNull($hosts['TV-Ruang-Tamu']['active']);
    }

    public function test_uses_the_active_field_when_the_onu_actually_reports_it(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $doc = $this->deviceDoc();
        $doc[0]['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host']['1']['Active'] = ['_value' => true];
        $doc[0]['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host']['2']['Active'] = ['_value' => false];

        Http::fake(['*/devices*' => Http::response($doc, 200)]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]))
            ->assertOk()
            ->assertJsonPath('active_count', 1)
            ->assertJsonPath('unknown_count', 0);

        $hosts = collect($response->json('hosts'))->keyBy('hostname');

        $this->assertTrue($hosts['Laptop-Budi']['active']);
        $this->assertFalse($hosts['TV-Ruang-Tamu']['active']);
        $this->assertSame('device', $hosts['TV-Ruang-Tamu']['active_source']);
    }

    public function test_unlinked_onu_is_reported_as_such_not_as_a_failure(): void
    {
        $olt = $this->makeOlt();
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson(route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 99]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_linked');

        // Tak boleh ada panggilan ke NBI untuk ONU yang memang belum berpasangan.
        Http::assertNothingSent();
    }

    public function test_reports_when_genieacs_is_not_configured(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson(route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_configured');
    }

    public function test_result_is_cached_so_reopening_the_panel_does_not_hit_the_acs_again(): void
    {
        Cache::flush();

        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        Http::fake(['*/devices*' => Http::response($this->deviceDoc(), 200)]);

        $admin = User::factory()->admin()->create();
        $url = route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]);

        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->actingAs($admin)->getJson($url)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_fresh_flag_bypasses_the_cache(): void
    {
        Cache::flush();

        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        Http::fake(['*/devices*' => Http::response($this->deviceDoc(), 200)]);

        $admin = User::factory()->admin()->create();
        $url = route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]);

        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->actingAs($admin)->getJson($url.'?fresh=1')->assertOk();

        Http::assertSentCount(2);
    }

    public function test_guests_cannot_read_connected_devices(): void
    {
        $olt = $this->makeOlt();

        $this->getJson(route('genieacs.onu.clients', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]))
            ->assertUnauthorized();
    }
}
