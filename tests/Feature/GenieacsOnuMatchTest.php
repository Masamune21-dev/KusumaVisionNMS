<?php

namespace Tests\Feature;

use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Genieacs\GenieacsDeviceSyncService;
use App\Services\Genieacs\GenieacsMapService;
use App\Services\Genieacs\GenieacsOnuMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pencocokan device GenieACS ke posisi ONU NMS.
 *
 * Angka & bentuk data di sini diambil dari armada sungguhan (22 Sep 2026):
 * serial C-Data `CDTCAF0012E6` berpasangan dengan PonMac `d0:5f:af:00:12:e7`
 * (selisih satu pada byte terakhir), dan OLT EPON menaruh MAC di kolom serial.
 */
class GenieacsOnuMatchTest extends TestCase
{
    use RefreshDatabase;

    private function matcher(): GenieacsOnuMatcher
    {
        return app(GenieacsOnuMatcher::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $onus
     */
    private function makeOlt(array $onus, string $name = 'OLT-UJI'): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => '10.0.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'ZTE C320'],
                'port_onus' => [
                    // Snapshot sungguhan menyertakan slot & port pada SETIAP entri ONU —
                    // `OnuInventoryService::collect()` membaca posisi dari sana, bukan dari
                    // kunci "1_1". Fixture harus meniru itu, kalau tidak seluruh ONU
                    // terbaca di slot 0 port 0.
                    '1_1' => [
                        'slot' => 1,
                        'port' => 1,
                        'count' => count($onus),
                        'onus' => array_map(fn (array $onu) => [...$onu, 'slot' => 1, 'port' => 1], $onus),
                    ],
                ],
            ],
        ]);
    }

    public function test_normalizes_serial_and_mac(): void
    {
        $this->assertSame('CDTCAF0012E6', GenieacsOnuMatcher::normalizeSerial(' cdtcaf0012e6 '));
        $this->assertNull(GenieacsOnuMatcher::normalizeSerial('   '));

        $this->assertSame('d05faf0012e7', GenieacsOnuMatcher::normalizeMac('D0:5F:AF:00:12:E7'));
        $this->assertSame('d05faf0012e7', GenieacsOnuMatcher::normalizeMac('d05f-af00-12e7'));
        // Potongan MAC bukan MAC.
        $this->assertNull(GenieacsOnuMatcher::normalizeMac('d0:5f:af'));
    }

    public function test_matches_by_exact_serial(): void
    {
        $olt = $this->makeOlt([
            ['onu_id' => 3, 'serial_number' => 'CDTCAF0012E6', 'online' => true],
        ]);

        $index = $this->matcher()->buildIndex([
            ['olt_id' => $olt->id, 'slot' => 1, 'port' => 1, 'onu_id' => 3, 'serial_number' => 'CDTCAF0012E6', 'mac' => null],
        ]);

        $hit = $this->matcher()->match(['serial' => 'cdtcaf0012e6', 'pon_mac' => null], $index);

        $this->assertSame('serial', $hit['method']);
        $this->assertSame(3, $hit['position']['onu_id']);
        $this->assertSame($olt->id, $hit['position']['snmp_olt_id']);
    }

    public function test_matches_by_mac_with_offset_of_one(): void
    {
        $index = $this->matcher()->buildIndex([
            ['olt_id' => 7, 'slot' => 1, 'port' => 2, 'onu_id' => 9, 'serial_number' => null, 'mac' => 'D0:5F:AF:00:12:E6'],
        ]);

        // MAC PON yang dilaporkan ONU berselisih satu dari yang dilihat OLT.
        $hit = $this->matcher()->match(['serial' => null, 'pon_mac' => 'd0:5f:af:00:12:e7'], $index);

        $this->assertSame('mac', $hit['method']);
        $this->assertSame(9, $hit['position']['onu_id']);
    }

    public function test_mac_offset_of_two_is_rejected(): void
    {
        $index = $this->matcher()->buildIndex([
            ['olt_id' => 7, 'slot' => 1, 'port' => 2, 'onu_id' => 9, 'serial_number' => null, 'mac' => 'D0:5F:AF:00:12:E6'],
        ]);

        // Toleransi dikunci di ±1; pengukuran menunjukkan ±2 tak menambah
        // cakupan, jadi melonggarkannya hanya menambah risiko salah pasang.
        $this->assertNull($this->matcher()->match(['serial' => null, 'pon_mac' => 'd0:5f:af:00:12:e8'], $index));
    }

    public function test_epon_serial_that_is_actually_a_mac_lands_in_the_mac_index(): void
    {
        $index = $this->matcher()->buildIndex([
            ['olt_id' => 5, 'slot' => 1, 'port' => 1, 'onu_id' => 2, 'serial_number' => 'D0:5F:AF:00:56:2F', 'mac' => null],
        ]);

        $hit = $this->matcher()->match(['serial' => null, 'pon_mac' => 'd0:5f:af:00:56:30'], $index);

        $this->assertSame('mac', $hit['method']);
        $this->assertSame(2, $hit['position']['onu_id']);
    }

    public function test_vendor_serial_that_looks_like_hex_is_not_treated_as_a_mac(): void
    {
        // 12 digit heksadesimal tanpa titik dua tetap serial, bukan MAC —
        // kalau tidak, serial vendor bisa salah tertarik ke indeks MAC.
        $index = $this->matcher()->buildIndex([
            ['olt_id' => 5, 'slot' => 1, 'port' => 1, 'onu_id' => 2, 'serial_number' => 'D05FAF0012E6', 'mac' => null],
        ]);

        $this->assertSame([], $index['mac']);
        $this->assertArrayHasKey('D05FAF0012E6', $index['serial']);
    }

    public function test_duplicate_key_is_marked_ambiguous_and_never_matched(): void
    {
        $index = $this->matcher()->buildIndex([
            ['olt_id' => 1, 'slot' => 1, 'port' => 1, 'onu_id' => 1, 'serial_number' => 'KEMBAR123456', 'mac' => null],
            ['olt_id' => 2, 'slot' => 1, 'port' => 1, 'onu_id' => 4, 'serial_number' => 'KEMBAR123456', 'mac' => null],
        ]);

        $this->assertNull($index['serial']['KEMBAR123456']);
        $this->assertNull($this->matcher()->match(['serial' => 'KEMBAR123456', 'pon_mac' => null], $index));
    }

    public function test_sync_stores_matches_and_skips_devices_that_have_no_pair(): void
    {
        $olt = $this->makeOlt([
            ['onu_id' => 1, 'serial_number' => 'CDTCAF0012E6', 'online' => true],
            ['onu_id' => 2, 'serial_number' => null, 'mac' => 'D0:5F:AF:00:12:E6', 'online' => true],
        ]);

        Http::fake(['*/devices*' => Http::response([
            ['_id' => 'dev-serial', '_deviceId' => ['_SerialNumber' => 'CDTCAF0012E6', '_Manufacturer' => 'CDTC', '_ProductClass' => 'FD512XW-R460'], '_lastInform' => '2026-09-22T10:00:00Z'],
            ['_id' => 'dev-mac', '_deviceId' => ['_SerialNumber' => 'ZTEGC0000392', '_Manufacturer' => 'ZTE', '_ProductClass' => 'F660'], 'VirtualParameters' => ['PonMac' => ['_value' => 'd0:5f:af:00:12:e7']]],
            ['_id' => 'dev-yatim', '_deviceId' => ['_SerialNumber' => 'TIDAKADA0001', '_Manufacturer' => 'ZTE', '_ProductClass' => 'F609']],
        ], 200)]);

        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $result = app(GenieacsDeviceSyncService::class)->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['devices']);
        $this->assertSame(1, $result['matched_serial']);
        $this->assertSame(1, $result['matched_mac']);
        $this->assertSame(1, $result['unmatched']);

        $serial = GenieacsDeviceMap::where('device_id', 'dev-serial')->firstOrFail();
        $this->assertSame($olt->id, $serial->snmp_olt_id);
        $this->assertSame(1, $serial->onu_id);
        $this->assertSame('serial', $serial->match_method);

        $mac = GenieacsDeviceMap::where('device_id', 'dev-mac')->firstOrFail();
        $this->assertSame(2, $mac->onu_id);
        $this->assertSame('mac', $mac->match_method);

        $yatim = GenieacsDeviceMap::where('device_id', 'dev-yatim')->firstOrFail();
        $this->assertNull($yatim->snmp_olt_id);
        $this->assertNull($yatim->match_method);
    }

    public function test_manual_assignment_survives_a_resync(): void
    {
        $olt = $this->makeOlt([
            ['onu_id' => 1, 'serial_number' => 'CDTCAF0012E6', 'online' => true],
        ]);

        GenieacsDeviceMap::create([
            'device_id' => 'dev-manual',
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
            'match_method' => GenieacsDeviceMap::METHOD_MANUAL,
            'matched_at' => now(),
        ]);

        Http::fake(['*/devices*' => Http::response([
            ['_id' => 'dev-manual', '_deviceId' => ['_SerialNumber' => 'SERIALLAIN01']],
        ], 200)]);

        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $result = app(GenieacsDeviceSyncService::class)->sync();

        $this->assertSame(1, $result['manual']);

        $row = GenieacsDeviceMap::where('device_id', 'dev-manual')->firstOrFail();
        $this->assertSame(GenieacsDeviceMap::METHOD_MANUAL, $row->match_method);
        $this->assertSame(1, $row->onu_id);
    }

    public function test_two_devices_claiming_the_same_onu_are_both_released(): void
    {
        $this->makeOlt([
            ['onu_id' => 1, 'serial_number' => 'CDTCAF0012E6', 'mac' => 'D0:5F:AF:00:12:E6', 'online' => true],
        ]);

        // Satu lewat serial, satu lewat MAC — keduanya menunjuk ONU yang sama.
        Http::fake(['*/devices*' => Http::response([
            ['_id' => 'dev-a', '_deviceId' => ['_SerialNumber' => 'CDTCAF0012E6']],
            ['_id' => 'dev-b', '_deviceId' => ['_SerialNumber' => 'LAIN00000001'], 'VirtualParameters' => ['PonMac' => ['_value' => 'd0:5f:af:00:12:e7']]],
        ], 200)]);

        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $result = app(GenieacsDeviceSyncService::class)->sync();

        $this->assertSame(2, $result['conflicts']);
        $this->assertSame(2, $result['unmatched']);
        $this->assertSame(0, GenieacsDeviceMap::whereNotNull('snmp_olt_id')->count());
    }

    public function test_devices_removed_from_acs_lose_their_row(): void
    {
        GenieacsDeviceMap::create(['device_id' => 'dev-lama']);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        Http::fake(['*/devices*' => Http::response([
            ['_id' => 'dev-baru', '_deviceId' => ['_SerialNumber' => 'BARU00000001']],
        ], 200)]);

        app(GenieacsDeviceSyncService::class)->sync();

        $this->assertDatabaseMissing('genieacs_device_map', ['device_id' => 'dev-lama']);
        $this->assertDatabaseHas('genieacs_device_map', ['device_id' => 'dev-baru']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        Http::fake(['*/devices*' => Http::response([
            ['_id' => 'dev-1', '_deviceId' => ['_SerialNumber' => 'APAPUN000001']],
        ], 200)]);

        $result = app(GenieacsDeviceSyncService::class)->sync(dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, GenieacsDeviceMap::count());
    }

    public function test_sync_fails_cleanly_when_genieacs_is_not_configured(): void
    {
        $result = app(GenieacsDeviceSyncService::class)->sync();

        $this->assertFalse($result['ok']);
        $this->assertSame('genieacs_not_configured', $result['error']);
    }

    public function test_port_page_carries_the_acs_badge_without_clobbering_the_tr069_endpoint(): void
    {
        $olt = $this->makeOlt([
            ['onu_id' => 1, 'serial_number' => 'CDTCAF0012E6', 'online' => true],
        ]);

        GenieacsDeviceMap::create([
            'device_id' => 'dev-1',
            'serial_number' => 'CDTCAF0012E6',
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
            'match_method' => GenieacsDeviceMap::METHOD_SERIAL,
            'matched_at' => now(),
            'last_inform_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('smartolt.port-onus', ['olt' => $olt->id, 'slot' => 1, 'port' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/PortOnus')
                // Penanda ter-ACS per ONU.
                ->where('genieacs_map.1.device_id', 'dev-1')
                ->where('genieacs_map.1.online', true)
                // Prop `acs` adalah endpoint CWMP untuk modal TR069 Massal —
                // BEDA hal, dan tidak boleh tertimpa oleh penanda di atas.
                ->has('acs.url')
                ->has('acs.username')
            );
    }

    public function test_last_inform_from_nbi_is_converted_to_app_timezone(): void
    {
        // GenieACS mengirim UTC. Tanpa konversi ke Asia/Jakarta, setiap device
        // tersimpan tertinggal 7 jam dan seluruh armada tampak "lama tak inform".
        config(['app.timezone' => 'Asia/Jakarta']);

        $utc = now('UTC')->subMinute();

        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        Http::fake(['*/devices*' => Http::response([[
            '_id' => 'dev-jam',
            '_deviceId' => ['_SerialNumber' => 'JAM000000001'],
            '_lastInform' => $utc->toIso8601ZuluString(),
        ]], 200)]);

        app(GenieacsDeviceSyncService::class)->sync();

        $row = GenieacsDeviceMap::where('device_id', 'dev-jam')->firstOrFail();

        // Selisih terhadap sekarang harus ~1 menit, bukan ~7 jam.
        $this->assertLessThan(120, abs($row->last_inform_at->diffInSeconds(now())));
    }

    public function test_stale_device_is_reported_as_not_online(): void
    {
        $olt = $this->makeOlt([['onu_id' => 1, 'serial_number' => 'CDTCAF0012E6', 'online' => true]]);

        GenieacsDeviceMap::create([
            'device_id' => 'dev-basi',
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
            'match_method' => GenieacsDeviceMap::METHOD_SERIAL,
            // Lebih tua dari ambang 2 jam. Satu jam TIDAK cukup: stempel ini
            // disegarkan scheduler 15 menit, jadi ambangnya sengaja longgar.
            'last_inform_at' => now()->subHours(3),
        ]);

        $map = app(GenieacsMapService::class)->forPort($olt->id, 1, 1);

        $this->assertFalse($map[1]['online']);
        $this->assertSame('dev-basi', $map[1]['device_id']);
    }

    public function test_port_map_exposes_pppoe_username_and_ip_for_the_onu_table(): void
    {
        $olt = $this->makeOlt([['onu_id' => 1, 'serial_number' => 'CDTCAF0012E6', 'online' => true]]);

        GenieacsDeviceMap::create([
            'device_id' => 'dev-pppoe',
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
            'match_method' => GenieacsDeviceMap::METHOD_SERIAL,
            'pppoe_username' => 'uji-0800-pppoe',
            'tr069_ip' => '10.99.0.12',
            'last_inform_at' => now(),
        ]);

        $map = app(GenieacsMapService::class)->forPort($olt->id, 1, 1);

        $this->assertSame('uji-0800-pppoe', $map[1]['pppoe_username']);
        $this->assertSame('10.99.0.12', $map[1]['ip']);
    }

    public function test_command_succeeds_quietly_when_genieacs_is_not_configured(): void
    {
        // Terjadwal tiap 15 menit — pemasangan yang tak memakai GenieACS tidak
        // boleh menghasilkan kegagalan berulang di log.
        $this->artisan('genieacs:match-onu')->assertSuccessful();
    }

    public function test_command_reports_failure_when_nbi_is_unreachable(): void
    {
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        Http::fake(['*' => Http::response('down', 500)]);

        $this->artisan('genieacs:match-onu')->assertFailed();
    }
}
