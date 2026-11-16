<?php

namespace Tests\Feature;

use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Services\Genieacs\GenieacsDeviceSyncService;
use App\Services\Genieacs\GenieacsRxReadings;
use App\Services\Hioso\HiosoEponSnmpService;
use App\Services\Hioso\HiosoSnmp;
use App\Services\OnuInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cadangan redaman dari TR-069 (`VirtualParameters.RXPower`) & lencana ACS yang ikut status OLT.
 *
 * Bentuk data meniru armada 23 Okt 2026: `RXPower` berupa string dBm ("-19.5") atau "N/A"
 * (±⅓ device), `_timestamp`-nya disegarkan tiap inform.
 */
class GenieacsRxFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $onus
     */
    private function makeOlt(array $onus): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI',
            'vendor' => 'HiOSO EPON 25355',
            'ip' => '10.0.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'port_onus' => [
                    '1_2' => [
                        'slot' => 1,
                        'port' => 2,
                        'count' => count($onus),
                        'onus' => array_map(fn (array $onu) => [...$onu, 'slot' => 1, 'port' => 2], $onus),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function mapRow(SnmpOlt $olt, int $onuId, array $extra = []): GenieacsDeviceMap
    {
        return GenieacsDeviceMap::create([
            'device_id' => "dev-{$onuId}",
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 2,
            'onu_id' => $onuId,
            'match_method' => GenieacsDeviceMap::METHOD_MAC,
            'last_inform_at' => now()->subMinutes(3),
            ...$extra,
        ]);
    }

    public function test_sync_stores_rx_power_with_its_own_timestamp_and_drops_na(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        $this->makeOlt([
            ['onu_id' => 1, 'serial_number' => 'D0:5F:AF:D2:9C:49', 'mac' => 'D0:5F:AF:D2:9C:49', 'online' => true],
            ['onu_id' => 2, 'serial_number' => 'C4:CD:50:36:F8:3D', 'mac' => 'C4:CD:50:36:F8:3D', 'online' => true],
        ]);
        $readAt = now('UTC')->subMinutes(2);

        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        Http::fake(['*/devices*' => Http::response([
            ['_id' => 'dev-rx', '_deviceId' => ['_SerialNumber' => 'DF77-0800000001'], '_lastInform' => now('UTC')->toIso8601ZuluString(),
                'VirtualParameters' => ['PonMac' => ['_value' => 'd0:5f:af:d2:9c:49'],
                    'RXPower' => ['_value' => '-19.5', '_timestamp' => $readAt->toIso8601ZuluString()]]],
            ['_id' => 'dev-na', '_deviceId' => ['_SerialNumber' => 'DF77-0800000002'],
                'VirtualParameters' => ['PonMac' => ['_value' => 'c4:cd:50:36:f8:3d'],
                    'RXPower' => ['_value' => 'N/A', '_timestamp' => $readAt->toIso8601ZuluString()]]],
        ], 200)]);

        $this->assertTrue(app(GenieacsDeviceSyncService::class)->sync()['ok']);

        $rx = GenieacsDeviceMap::where('device_id', 'dev-rx')->firstOrFail();
        $this->assertSame(1, $rx->onu_id);
        $this->assertSame(-19.5, $rx->rx_power_dbm);
        // Stempel bacaan (UTC dari NBI) dikonversi ke zona app, seperti `_lastInform`.
        $this->assertLessThan(10, abs($rx->rx_power_at->diffInSeconds($readAt)));

        $na = GenieacsDeviceMap::where('device_id', 'dev-na')->firstOrFail();
        $this->assertNull($na->rx_power_dbm);
        $this->assertNull($na->rx_power_at);
    }

    public function test_readings_only_return_fresh_values_of_that_olt(): void
    {
        $olt = $this->makeOlt([]);
        $other = $this->makeOlt([]);

        $this->mapRow($olt, 1, ['rx_power_dbm' => -19.5, 'rx_power_at' => now()->subMinutes(5)]);
        // Lebih tua dari batas umur: ONU berhenti melapor atau sinkronisasi macet.
        $this->mapRow($olt, 2, ['rx_power_dbm' => -18.0, 'rx_power_at' => now()->subMinutes(GenieacsRxReadings::MAX_AGE_MINUTES + 5)]);
        $this->mapRow($olt, 3);
        GenieacsDeviceMap::create([
            'device_id' => 'dev-lain', 'snmp_olt_id' => $other->id, 'slot' => 1, 'port' => 2, 'onu_id' => 1,
            'rx_power_dbm' => -20.0, 'rx_power_at' => now(),
        ]);

        $readings = app(GenieacsRxReadings::class)->forOlt($olt);

        $this->assertSame(['1.2.1'], array_keys($readings));
        $this->assertSame(-19.5, $readings['1.2.1']['dbm']);
    }

    /**
     * Jalur produksi: driver di-resolve container (bukan `new` dengan pembaca palsu). Regresi 23 Okt
     * 2026 — `?GenieacsRxReadings $tr069Rx = null` di-resolve container sebagai null, cadangan mati
     * diam-diam walau semua unit test hijau.
     */
    public function test_container_resolved_hioso_driver_uses_tr069_readings(): void
    {
        $olt = $this->makeOlt([]);
        $this->mapRow($olt, 1, ['rx_power_dbm' => -19.5, 'rx_power_at' => now()->subMinutes(3)]);

        $base = '1.3.6.1.4.1.25355.3.2.6';
        $this->app->instance(HiosoSnmp::class, new class($base) extends HiosoSnmp
        {
            public function __construct(private string $base) {}

            public function get(SnmpOlt $olt, string $oid): ?string
            {
                return null;
            }

            public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = 10_000_000, int $retries = 3): array
            {
                return match ($oid) {
                    "{$this->base}.3.2.1.11.1" => ["{$oid}.2.1" => 'd05fafd29c49'],
                    "{$this->base}.3.2.1.37.1" => ["{$oid}.2.1" => 'pelanggan-uji'],
                    "{$this->base}.14.2.1.8.1" => ["{$oid}.2.1" => 'na'],
                    "{$this->base}.3.2.1.39.1" => ["{$oid}.2.1" => '1'],
                    default => [],
                };
            }
        });

        $onu = app(HiosoEponSnmpService::class)->getRegisteredOnus($olt)[0];

        $this->assertTrue($onu['online']);
        $this->assertSame(-19.5, $onu['rx_power_dbm']);
        $this->assertSame('tr069', $onu['rx_power_source']);
    }

    /**
     * ONU yang offline/LOS di OLT tetap punya inform baru (< 2 jam) — lencana ACS tak boleh "online".
     */
    public function test_inventory_marks_acs_offline_when_onu_is_offline_at_olt(): void
    {
        $olt = $this->makeOlt([
            ['onu_id' => 1, 'serial_number' => 'D0:5F:AF:D2:9C:49', 'online' => true],
            ['onu_id' => 2, 'serial_number' => 'C4:CD:50:36:F8:3D', 'online' => false, 'phase_state' => 'LOS'],
        ]);
        $this->mapRow($olt, 1);
        $this->mapRow($olt, 2);

        $onus = collect(app(OnuInventoryService::class)->forPort($olt, 1, 2)['onus'])->keyBy('onu_id');

        $this->assertTrue($onus[1]['acs']['online']);
        $this->assertFalse($onus[2]['acs']['online']);
        $this->assertSame('dev-2', $onus[2]['acs']['device_id'], 'Pasangannya tetap ada — hanya status yang ikut OLT');
    }
}
