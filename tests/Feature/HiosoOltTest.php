<?php

namespace Tests\Feature;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\Hioso\HiosoFaceplateService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Support\SmartOltSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fake writer HiOSO: rekam pemanggilan, tak menyentuh telnet. */
class FakeHiosoCliWriteService extends HiosoCliWriteService
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    public function setName(SnmpOlt $olt, int $port, int $onuId, ?string $name): array
    {
        $this->calls[] = ['name', $port, $onuId, $name];

        return ['ok' => true, 'output' => '', 'error' => null];
    }

    public function reboot(SnmpOlt $olt, int $port, int $onuId): array
    {
        $this->calls[] = ['reboot', $port, $onuId];

        return ['ok' => true, 'output' => '', 'error' => null];
    }

    public function delete(SnmpOlt $olt, int $port, int $onuId): array
    {
        $this->calls[] = ['delete', $port, $onuId];

        return ['ok' => true, 'output' => '', 'error' => null];
    }
}

/**
 * OLT HiOSO / V-Sol EPON (25355) kini punya controller + rute (`hioso-olt.*`) + halaman (`Hioso/*`)
 * sendiri, terpisah dari C-Data.
 */
class HiosoOltTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind driver + resolver + faceplate HiOSO palsu supaya scan-on-create deterministik & cepat
     * tanpa SNMP/CLI nyata (SNMP HiOSO memakai timeout WAN panjang). $serial menandai ONU hasil scan.
     */
    private function fakeHiosoScan(string $serial = 'HIOSOSN'): void
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
                return ['sys_name' => 'HIOSO-FAKE', 'firmware' => '1.0.0.1/HA7304'];
            }

            public function getPorts(SnmpOlt $olt): array
            {
                return [['slot' => 1, 'port' => 1, 'name' => 'epon 0/1/1', 'oper_status' => 'unknown']];
            }

            public function getRegisteredOnus(SnmpOlt $olt): array
            {
                return [[
                    'onu_key' => '1.1', 'slot' => 1, 'port' => 1, 'onu_id' => 1,
                    'serial_number' => $this->serial, 'mac' => $this->serial, 'name' => 'Uji',
                    'interface' => 'epon 0/1/1:1', 'online' => true, 'rx_power_dbm' => -20.36,
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

        $faceplate = new class extends HiosoFaceplateService
        {
            public function __construct() {}

            public function build(SnmpOlt $olt, array $ports): ?array
            {
                return null;
            }
        };
        $this->app->instance(HiosoFaceplateService::class, $faceplate);
    }

    private function hiosoOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'OLT-HIOSO',
            'vendor' => 'HiOSO EPON 25355',
            'ip' => '10.30.0.51',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ], $overrides));
    }

    public function test_create_form_presets_hioso_vendor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('hioso-olt.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Hioso/Create')
                ->where('defaults.vendor', 'HiOSO EPON 25355'));
    }

    public function test_store_runs_initial_scan_and_redirects_to_hioso_tab(): void
    {
        $user = User::factory()->create();
        $this->fakeHiosoScan('HIOSOSCAN1');

        $this->actingAs($user)
            ->post(route('hioso-olt.store'), [
                'name' => 'OLT-HIOSO-NEW',
                'vendor' => 'HiOSO EPON 25355',
                'ip' => '10.30.0.60',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
            ])
            ->assertRedirect(route('smartolt.index', ['tab' => 'hioso']));

        $olt = SnmpOlt::where('ip', '10.30.0.60')->firstOrFail();
        $this->assertSame(SmartOltSupport::DRIVER_HIOSO_EPON, SmartOltSupport::driverKey($olt));
        $this->assertSame('HIOSOSCAN1', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.serial_number'));

        // Global search: ONU HiOSO tautkan ke halaman hioso-olt (bukan cdata-olt).
        $this->actingAs($user)
            ->getJson(route('dashboard.search', ['q' => 'HIOSOSCAN1']))
            ->assertOk()
            ->assertJsonPath('results.0.label', 'HIOSOSCAN1');

        $url = $this->actingAs($user)
            ->getJson(route('dashboard.search', ['q' => 'HIOSOSCAN1']))
            ->json('results.0.url');
        $this->assertStringContainsString('hioso-olt', $url);
    }

    public function test_detail_and_port_onus_pages_render_from_cache(): void
    {
        $user = User::factory()->create();
        $olt = $this->hiosoOlt([
            'last_test_result' => [
                // Fresh → ensureFreshScan tak memanggil scanner (tak ada SNMP nyata di test).
                'onu_scanned_at' => now()->toIso8601String(),
                'system' => ['sys_name' => 'HIOSO'],
                'ports' => [['slot' => 1, 'port' => 1, 'name' => 'epon 0/1/1', 'oper_status' => 'up']],
                'port_onus' => [
                    '1_1' => [
                        'ok' => true, 'slot' => 1, 'port' => 1, 'count' => 1,
                        'onus' => [[
                            'onu_key' => '1.1', 'slot' => 1, 'port' => 1, 'onu_id' => 1,
                            'serial_number' => 'ABC', 'mac' => 'ABC', 'name' => 'Uji',
                            'interface' => 'epon 0/1/1:1', 'online' => true, 'rx_power_dbm' => -20.36,
                        ]],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('hioso-olt.detail', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Hioso/Detail'));

        $this->actingAs($user)
            ->get(route('hioso-olt.port-onus', [$olt, 1, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Hioso/PortOnus'));
    }

    public function test_hioso_olt_edit_page_renders(): void
    {
        $user = User::factory()->create();
        $olt = $this->hiosoOlt();

        $this->actingAs($user)
            ->get(route('hioso-olt.edit', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Hioso/Edit'));
    }

    public function test_delete_calls_cli_and_removes_from_cache(): void
    {
        $user = User::factory()->create();
        $fake = new FakeHiosoCliWriteService;
        $this->app->instance(HiosoCliWriteService::class, $fake);

        $olt = $this->hiosoOlt([
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1, 'port' => 1, 'count' => 1,
                        'onus' => [
                            ['onu_key' => '1.5', 'slot' => 1, 'port' => 1, 'onu_id' => 5, 'name' => 'Old', 'interface' => 'epon 0/1/1:5'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('hioso-olt.onu.delete', [$olt, 1, 1, 5]))
            ->assertRedirect(route('hioso-olt.port-onus', [$olt, 1, 1]))
            ->assertSessionHas('success');

        // Delete dipanggil dgn (port, onuId) — HiOSO CLI `delete onu {id}` di interface epon 0/{port}.
        $this->assertSame([['delete', 1, 5]], $fake->calls);

        $olt->refresh();
        $this->assertSame([], data_get($olt->last_test_result, 'port_onus.1_1.onus'));
        $this->assertSame(0, data_get($olt->last_test_result, 'port_onus.1_1.count'));
    }
}
