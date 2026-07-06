<?php

namespace Tests\Feature;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\SmartOltSnmpServiceResolver;
use App\Support\SmartOltSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CDataOltInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind resolver C-Data palsu supaya scan (auto-refresh halaman & scan-on-create) deterministik
     * dan cepat tanpa SNMP/CLI nyata. $serial menandai ONU hasil scan.
     */
    private function fakeScanDriver(string $serial = 'SCANNEDSN'): void
    {
        $driver = new class($serial) implements SmartOltSnmpDriver
        {
            public function __construct(private string $serial) {}

            public function ping(SnmpOlt $olt): bool
            {
                return true;
            }

            public function getSystemInfo(SnmpOlt $olt): array
            {
                return ['sys_name' => 'CDATA-FAKE', 'firmware_v3' => true];
            }

            public function getPorts(SnmpOlt $olt): array
            {
                return [['slot' => 0, 'port' => 1, 'name' => 'gpon 0/0/1']];
            }

            public function getRegisteredOnus(SnmpOlt $olt): array
            {
                return [[
                    'onu_key' => '0.1.5', 'slot' => 0, 'port' => 1, 'onu_id' => 5,
                    'serial_number' => $this->serial, 'name' => 'Uji', 'interface' => 'gpon 0/0/1:5', 'online' => true,
                ]];
            }

            public function getRegisteredOnusByPort(SnmpOlt $olt, int $slot, int $port): array
            {
                return array_values(array_filter(
                    $this->getRegisteredOnus($olt),
                    fn (array $o) => $o['slot'] === $slot && $o['port'] === $port,
                ));
            }

            public function getPortRxMap(SnmpOlt $olt): array
            {
                return [];
            }

            public function countRegisteredOnus(SnmpOlt $olt): int
            {
                return 1;
            }

            public function getUnconfiguredOnus(SnmpOlt $olt): array
            {
                return [];
            }
        };

        $resolver = new class($driver) extends SmartOltSnmpServiceResolver
        {
            public function __construct(private SmartOltSnmpDriver $driver) {}

            public function resolve(SnmpOlt $olt): SmartOltSnmpDriver
            {
                return $this->driver;
            }
        };

        $this->app->instance(SmartOltSnmpServiceResolver::class, $resolver);
    }

    public function test_cdata_olt_index_redirects_to_smartolt_cdata_tab(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('cdata-olt.index'))
            ->assertRedirect(route('smartolt.index', ['tab' => 'cdata']));
    }

    public function test_create_and_edit_pages_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('cdata-olt.create'))->assertOk();

        $olt = SnmpOlt::create([
            'name' => 'CDATA-EPON-1',
            'vendor' => 'C-Data EPON 17409',
            'ip' => '10.20.0.6',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->actingAs($user)->get(route('cdata-olt.edit', $olt))->assertOk();
    }

    public function test_stored_cdata_olt_appears_only_in_cdata_index_not_smartolt(): void
    {
        $user = User::factory()->create();
        $this->fakeScanDriver();

        $this->actingAs($user)
            ->post(route('cdata-olt.store'), [
                'name' => 'CDATA-GPON-1',
                'vendor' => 'C-Data GPON 34592',
                'ip' => '10.20.0.5',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
                'cli_transport' => 'telnet',
                'cli_port' => 23,
                'polling_enabled' => true,
                'poll_interval_minutes' => 5,
                'rx_poll_interval_minutes' => 5,
            ])
            ->assertRedirect(route('smartolt.index', ['tab' => 'cdata']));

        $olt = SnmpOlt::where('ip', '10.20.0.5')->firstOrFail();
        $this->assertSame(SmartOltSupport::DRIVER_CDATA_GPON, SmartOltSupport::driverKey($olt));

        // Rute lama OLT C-Data kini redirect ke tab C-Data di halaman SmartOLT.
        $this->actingAs($user)
            ->get(route('cdata-olt.index'))
            ->assertRedirect(route('smartolt.index', ['tab' => 'cdata']));

        // Muncul di tab C-Data, tidak bocor ke daftar ZTE — keduanya satu halaman SmartOLT.
        $this->actingAs($user)
            ->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page->component('SmartOlt/Index')
                ->has('olts', 0)
                ->has('cdataOlts', 1));
    }

    public function test_same_ip_allowed_with_different_snmp_port(): void
    {
        $user = User::factory()->create();
        $this->fakeScanDriver();

        $payload = fn (string $name, int $port): array => [
            'name' => $name,
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.20.0.9',
            'snmp_port' => $port,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'polling_enabled' => true,
            'poll_interval_minutes' => 5,
            'rx_poll_interval_minutes' => 5,
        ];

        // IP sama, port beda → boleh (dua OLT tersimpan).
        $this->actingAs($user)->post(route('cdata-olt.store'), $payload('OLT-A', 161))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('cdata-olt.store'), $payload('OLT-B', 1161))->assertSessionHasNoErrors();
        $this->assertSame(2, SnmpOlt::where('ip', '10.20.0.9')->count());

        // IP sama, port sama → ditolak.
        $this->actingAs($user)
            ->post(route('cdata-olt.store'), $payload('OLT-C', 161))
            ->assertSessionHasErrors('ip');
        $this->assertSame(2, SnmpOlt::where('ip', '10.20.0.9')->count());
    }

    public function test_detail_and_port_onus_pages_render(): void
    {
        $user = User::factory()->create();
        $this->fakeScanDriver();
        $olt = SnmpOlt::create([
            'name' => 'CDATA-GPON-2',
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.20.0.7',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->actingAs($user)
            ->get(route('cdata-olt.detail', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CDataOlt/Detail'));

        // slot 0 valid untuk GPON C-Data.
        $this->actingAs($user)
            ->get(route('cdata-olt.port-onus', [$olt, 0, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CDataOlt/PortOnus'));
    }

    public function test_store_runs_initial_scan_so_onu_is_searchable(): void
    {
        $user = User::factory()->create();
        $this->fakeScanDriver('INITSCAN1');

        $this->actingAs($user)
            ->post(route('cdata-olt.store'), [
                'name' => 'CDATA-EPON-SCAN',
                'vendor' => 'C-Data EPON 17409',
                'ip' => '10.20.0.20',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
            ])
            ->assertRedirect(route('smartolt.index', ['tab' => 'cdata']));

        $olt = SnmpOlt::where('ip', '10.20.0.20')->firstOrFail();
        $this->assertSame('INITSCAN1', data_get($olt->last_test_result, 'port_onus.0_1.onus.0.serial_number'));

        // Langsung muncul di global search tanpa pernah membuka halaman OLT.
        $this->actingAs($user)
            ->getJson(route('dashboard.search', ['q' => 'INITSCAN1']))
            ->assertOk()
            ->assertJsonPath('results.0.label', 'INITSCAN1');
    }

    public function test_detail_auto_scans_when_cache_is_stale(): void
    {
        $user = User::factory()->create();
        $this->fakeScanDriver('FRESHSCAN1');

        $olt = SnmpOlt::create([
            'name' => 'CDATA-GPON-STALE',
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.20.0.21',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => ['onu_scanned_at' => now()->subMinutes(10)->toIso8601String()],
        ]);

        $this->actingAs($user)->get(route('cdata-olt.detail', $olt))->assertOk();

        $olt->refresh();
        $this->assertSame('FRESHSCAN1', data_get($olt->last_test_result, 'port_onus.0_1.onus.0.serial_number'));
    }

    public function test_detail_skips_scan_when_cache_is_fresh(): void
    {
        $user = User::factory()->create();
        $this->fakeScanDriver('SHOULDNOTAPPEAR');

        $olt = SnmpOlt::create([
            'name' => 'CDATA-GPON-FRESH',
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.20.0.22',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'onu_scanned_at' => now()->subMinutes(1)->toIso8601String(),
                'port_onus' => [
                    '0_1' => [
                        'slot' => 0, 'port' => 1,
                        'onus' => [['onu_id' => 9, 'slot' => 0, 'port' => 1, 'serial_number' => 'CACHEDSN']],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)->get(route('cdata-olt.detail', $olt))->assertOk();

        $olt->refresh();
        // Cache masih dalam jendela TTL → tidak re-scan, data lama dipertahankan.
        $this->assertSame('CACHEDSN', data_get($olt->last_test_result, 'port_onus.0_1.onus.0.serial_number'));
    }

    public function test_global_search_links_cdata_onu_to_cdata_route(): void
    {
        $user = User::factory()->create();
        SnmpOlt::create([
            'name' => 'CDATA-GPON-3',
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.20.0.8',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'port_onus' => [
                    '0_1' => [
                        'slot' => 0,
                        'port' => 1,
                        'onus' => [
                            ['onu_key' => '0.1.5', 'slot' => 0, 'port' => 1, 'onu_id' => 5, 'serial_number' => 'TESTSN12345', 'name' => 'Pelanggan Uji', 'interface' => 'gpon 0/0/1:5'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('dashboard.search', ['q' => 'TESTSN12345']));

        $response->assertOk();
        $url = $response->json('results.0.url');
        $this->assertStringContainsString('cdata-olt', $url);
        $this->assertStringContainsString('ports/0/1', $url); // slot 0 tetap terlink
    }

    public function test_zte_olt_is_not_listed_in_cdata_tab(): void
    {
        $user = User::factory()->create();

        SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        // OLT ZTE muncul di daftar ZTE, bukan di daftar C-Data (tab terpisah, satu halaman).
        $this->actingAs($user)
            ->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page->component('SmartOlt/Index')
                ->has('olts', 1)
                ->has('cdataOlts', 0)
                ->has('hiosoOlts', 0));
    }

    public function test_hioso_olt_appears_in_hioso_tab_not_cdata_or_zte(): void
    {
        $user = User::factory()->create();

        $olt = SnmpOlt::create([
            'name' => 'OLT-HIOSO-NDOKATON',
            'vendor' => 'HiOSO EPON 25355',
            'ip' => '10.30.0.9',
            'snmp_port' => 2238,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->assertSame(SmartOltSupport::DRIVER_HIOSO_EPON, SmartOltSupport::driverKey($olt));

        // Tab HiOSO terpisah: muncul di hiosoOlts, bukan di cdataOlts maupun olts (ZTE).
        $this->actingAs($user)
            ->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page->component('SmartOlt/Index')
                ->has('olts', 0)
                ->has('cdataOlts', 0)
                ->has('hiosoOlts', 1)
                ->where('hiosoOlts.0.driver', SmartOltSupport::DRIVER_HIOSO_EPON));
    }
}
