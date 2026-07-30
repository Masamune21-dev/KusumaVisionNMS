<?php

namespace Tests\Feature;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\SmartOltSnmpServiceResolver;
use App\Support\SmartOltSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OLT HsAirPo / HSGQ EPON (Photon 12170) — Fase A: family baru dengan controller + rute
 * (`hsairpo-olt.*`) + halaman (`HsAirPo/*`) sendiri, read-only, inventori ONU dari CLI.
 */
class HsAirPoOltTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Driver palsu supaya scan-on-create deterministik tanpa SNMP/telnet nyata.
     */
    private function fakeHsAirPoScan(string $mac = '0C:37:47:78:E9:27'): void
    {
        $driver = new class($mac) implements SmartOltSnmpDriver
        {
            public function __construct(private string $mac) {}

            public function ping(SnmpOlt $olt): bool
            {
                return true;
            }

            public function getSystemInfo(SnmpOlt $olt): array
            {
                return [
                    'sys_name' => 'EPON-OLT',
                    'sys_object_id' => '.1.3.6.1.4.1.12170.2.3',
                    'firmware' => '1.1.2.20210408_release (build-145001)',
                    'product' => '4PON EPON-OLT',
                ];
            }

            public function getPorts(SnmpOlt $olt): array
            {
                return [['if_index' => 5002, 'slot' => 1, 'port' => 1, 'name' => 'pon1', 'oper_status' => 'up']];
            }

            public function getRegisteredOnus(SnmpOlt $olt): array
            {
                return [[
                    'onu_key' => '1.1', 'slot' => 1, 'port' => 1, 'onu_id' => 1,
                    'serial_number' => $this->mac, 'mac' => $this->mac, 'name' => null,
                    'interface' => 'pon1:1', 'online' => true, 'rx_power_dbm' => null,
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

            public function driverKey(SnmpOlt $olt): string
            {
                return SmartOltSupport::DRIVER_HSAIRPO_EPON;
            }
        };
        $this->app->instance(SmartOltSnmpServiceResolver::class, $resolver);
    }

    private function hsairpoOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'OLT-HSAIRPO',
            'vendor' => 'HsAirPo HSGQ EPON 12170',
            'ip' => '10.40.0.10',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ], $overrides));
    }

    public function test_inventory_page_partitions_hsairpo_olts_into_their_own_tab(): void
    {
        $user = User::factory()->create();
        $this->hsairpoOlt();

        $this->actingAs($user)
            ->get(route('smartolt.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SmartOlt/Index')
                ->has('hsairpoOlts', 1)
                ->has('olts', 0)
                ->has('cdataOlts', 0)
                ->has('hiosoOlts', 0)
                ->where('hsairpoOlts.0.driver', SmartOltSupport::DRIVER_HSAIRPO_EPON));
    }

    public function test_create_form_presets_hsairpo_vendor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('hsairpo-olt.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HsAirPo/Create')
                ->where('defaults.vendor', 'HsAirPo HSGQ EPON 12170'));
    }

    public function test_store_runs_initial_scan_and_links_search_to_hsairpo_routes(): void
    {
        $user = User::factory()->create();
        $this->fakeHsAirPoScan('AA:BB:CC:11:22:33');

        $this->actingAs($user)
            ->post(route('hsairpo-olt.store'), [
                'name' => 'OLT-HSAIRPO-NEW',
                'vendor' => 'HsAirPo HSGQ EPON 12170',
                'ip' => '10.40.0.20',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
                'cli_transport' => 'telnet',
                'cli_port' => 12167,
                'cli_username' => 'admin',
                'cli_password' => 'secret',
            ])
            ->assertRedirect(route('smartolt.index', ['tab' => 'hsairpo']));

        $olt = SnmpOlt::where('ip', '10.40.0.20')->firstOrFail();
        $this->assertSame(SmartOltSupport::DRIVER_HSAIRPO_EPON, SmartOltSupport::driverKey($olt));
        $this->assertSame('AA:BB:CC:11:22:33', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.serial_number'));

        // Global search: ONU HsAirPo menaut ke halaman hsairpo-olt (bukan cdata-olt / smartolt).
        $url = $this->actingAs($user)
            ->getJson(route('dashboard.search', ['q' => 'AA:BB:CC:11:22:33']))
            ->assertOk()
            ->json('results.0.url');

        $this->assertStringContainsString('hsairpo-olt', (string) $url);
    }

    public function test_detail_and_port_onus_pages_render_from_cache(): void
    {
        $user = User::factory()->create();
        $olt = $this->hsairpoOlt([
            'last_test_result' => [
                // Fresh → ensureFreshScan tak memanggil scanner (tak ada SNMP/CLI nyata di test).
                'onu_scanned_at' => now()->toIso8601String(),
                'system' => ['sys_name' => 'EPON-OLT', 'product' => '4PON EPON-OLT'],
                'ports' => [['if_index' => 5002, 'slot' => 1, 'port' => 1, 'name' => 'pon1', 'oper_status' => 'up']],
                'port_onus' => [
                    '1_1' => [
                        'ok' => true, 'slot' => 1, 'port' => 1, 'count' => 1,
                        'onus' => [[
                            'onu_key' => '1.1', 'slot' => 1, 'port' => 1, 'onu_id' => 1,
                            'serial_number' => 'AA:BB:CC:DD:EE:FF', 'mac' => 'AA:BB:CC:DD:EE:FF',
                            'interface' => 'pon1:1', 'online' => true,
                        ]],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('hsairpo-olt.detail', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HsAirPo/Detail')
                ->where('olt.capabilities.driver', SmartOltSupport::DRIVER_HSAIRPO_EPON));

        $this->actingAs($user)
            ->get(route('hsairpo-olt.port-onus', [$olt, 1, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HsAirPo/PortOnus')
                ->where('snapshot.onus.0.mac', 'AA:BB:CC:DD:EE:FF'));
    }

    public function test_edit_page_renders(): void
    {
        $user = User::factory()->create();
        $olt = $this->hsairpoOlt();

        $this->actingAs($user)
            ->get(route('hsairpo-olt.edit', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HsAirPo/Edit'));
    }

    public function test_update_redirects_to_hsairpo_tab_and_keeps_secrets(): void
    {
        $user = User::factory()->create();
        $olt = $this->hsairpoOlt();

        $this->actingAs($user)
            ->put(route('hsairpo-olt.update', $olt), [
                'name' => 'OLT-HSAIRPO-RENAMED',
                'vendor' => 'HsAirPo HSGQ EPON 12170',
                'ip' => '10.40.0.10',
                'snmp_port' => 161,
                'snmp_version' => 'v2c',
                'snmp_read_community' => '',
                'cli_transport' => 'telnet',
                'cli_password' => '',
            ])
            ->assertRedirect(route('smartolt.index', ['tab' => 'hsairpo']));

        $olt->refresh();
        $this->assertSame('OLT-HSAIRPO-RENAMED', $olt->name);
        // Field rahasia dikosongkan di form → nilai lama dipertahankan.
        $this->assertSame('public', $olt->snmp_read_community);
        $this->assertSame('secret', $olt->cli_password);
    }

    public function test_ssh_transport_is_rejected(): void
    {
        $user = User::factory()->create();
        $olt = $this->hsairpoOlt();

        $this->actingAs($user)
            ->put(route('hsairpo-olt.update', $olt), [
                'name' => 'OLT-HSAIRPO',
                'vendor' => 'HsAirPo HSGQ EPON 12170',
                'ip' => '10.40.0.10',
                'snmp_port' => 161,
                'snmp_version' => 'v2c',
                'cli_transport' => 'ssh',
            ])
            ->assertSessionHasErrors('cli_transport');
    }

    public function test_write_routes_registered_for_hsairpo(): void
    {
        // Aktif: rename/reboot/delete via CLI config.
        foreach (['hsairpo-olt.onu.reboot', 'hsairpo-olt.onu.info', 'hsairpo-olt.onu.delete'] as $name) {
            $this->assertNotNull(app('router')->getRoutes()->getByName($name), "rute {$name} harus terdaftar");
        }

        // Belum: enable/disable (semantik activate/no-activate belum diuji) & save-config.
        foreach (['hsairpo-olt.onu.state', 'hsairpo-olt.config.save'] as $name) {
            $this->assertNull(app('router')->getRoutes()->getByName($name), "rute {$name} belum ada");
        }
    }
}
