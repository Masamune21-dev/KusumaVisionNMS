<?php

namespace Tests\Feature;

use App\Models\SmartOltCardStatus;
use App\Models\SmartOltInterfaceStatus;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SmartOltHardwareInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'BMKV-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ], $overrides));
    }

    public function test_detail_reads_hardware_from_database_without_cli(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        SmartOltCardStatus::create([
            'snmp_olt_id' => $olt->id,
            'rack' => 1,
            'shelf' => 1,
            'slot' => 20,
            'cfg_type' => 'HUVQ',
            'real_type' => 'HUVQ',
            'port_count' => 4,
            'hard_ver' => 'V1.0.0',
            'soft_ver' => 'V2.1.0',
            'status' => 'INSERVICE',
            'refreshed_at' => now(),
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                throw new RuntimeException('CLI should not run while rendering detail.');
            }
        });

        $response = $this->actingAs($user)->get(route('smartolt.detail', $olt));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SmartOlt/Detail')
            ->has('cards', 1)
            ->where('cards.0.cfg_type', 'HUVQ')
        );
    }

    public function test_hardware_refresh_persists_show_card_output(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return [
                    'ok' => true,
                    'error' => null,
                    'output' => <<<'OUT'
Rack Shelf Slot CfgType RealType Port  HardVer SoftVer         Status
-------------------------------------------------------------------------------
1    1     2    GTGH    GTGHG    16    V1.0.0  V2.1.0          INSERVICE
1    1     20   HUVQ    HUVQ     4     V1.0.0  V2.1.0          INSERVICE
OUT,
                ];
            }
        });

        // Fake the SNMP processor walk so the refresh stays hermetic (no real network I/O).
        $this->app->instance(OltSnmpClient::class, new class extends OltSnmpClient
        {
            public function cardProcessors(SnmpOlt $olt): array
            {
                return [
                    '1.1.2' => ['cpu' => 26, 'mem' => 21, 'phy_mem' => 1024],
                    '1.1.20' => ['cpu' => 17, 'mem' => 7, 'phy_mem' => 128],
                ];
            }

            public function uplinkInterfaceNames(SnmpOlt $olt): array
            {
                return [];
            }
        });

        $response = $this->actingAs($user)->post(route('smartolt.hardware.refresh', $olt));

        $response->assertRedirect(route('smartolt.detail', $olt));
        $this->assertDatabaseHas('smartolt_card_statuses', [
            'snmp_olt_id' => $olt->id,
            'slot' => 2,
            'cfg_type' => 'GTGH',
            'status' => 'INSERVICE',
            'cpu_load' => 26,
            'mem_load' => 21,
            'phy_mem_mb' => 1024,
        ]);
        $this->assertDatabaseHas('smartolt_card_statuses', [
            'snmp_olt_id' => $olt->id,
            'slot' => 20,
            'cfg_type' => 'HUVQ',
            'port_count' => 4,
            'cpu_load' => 17,
            'mem_load' => 7,
            'phy_mem_mb' => 128,
        ]);
    }

    public function test_port_detail_refresh_persists_uplink_interface(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return [
                    'ok' => true,
                    'error' => null,
                    'output' => <<<'OUT'
> show interface port-status xgei_1/20/1
--------------------------------------------------------------------------------
     Port      hybrid  Native Negotiation  Speed  Duplex Flow-   Admin      Link
               Status  VLAN     auto       (Mbps)        Ctrl    Status
--------------------------------------------------------------------------------
xgei_1/20/1    optical  1       disable    10000   full  disable activate   up
BMKV-C300#
> show vlan port xgei_1/20/1
TaggedVlan:
100,200-202
BMKV-C300#
> show interface optical-module-info xgei_1/20/1
 No optical module present.
BMKV-C300#
OUT,
                ];
            }
        });

        $this->fakeUplinkNames(['20/1' => 'xgei_1/20/1']);

        $response = $this->actingAs($user)->post(route('smartolt.port.refresh', $olt), [
            'interface' => 'xgei_1/20/1',
        ]);

        $response->assertRedirect(route('smartolt.port.detail', ['olt' => $olt->id, 'interface' => 'xgei_1/20/1']));

        $uplink = SmartOltInterfaceStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('interface', 'xgei_1/20/1')
            ->firstOrFail();

        $this->assertSame('uplink', $uplink->interface_type);
        $this->assertSame('up', $uplink->link_status);
        $this->assertSame(10000, $uplink->speed_mbps);
        $this->assertSame(['100', '200-202'], $uplink->tagged_vlans);
        $this->assertNull($uplink->tx_power_dbm);

        $response = $this->actingAs($user)->get(route('smartolt.port.detail', ['olt' => $olt->id, 'interface' => 'xgei_1/20/1']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SmartOlt/PortDetail')
            ->where('type', 'uplink')
            ->where('interface', 'xgei_1/20/1')
            ->where('detail.interface', 'xgei_1/20/1')
        );
    }

    /**
     * @param  array<string, string>  $names  "slot/port" => nama interface asli (ifName OLT)
     */
    private function fakeUplinkNames(array $names): void
    {
        $this->app->instance(OltSnmpClient::class, new class($names) extends OltSnmpClient
        {
            public function __construct(private array $names) {}

            public function cardProcessors(SnmpOlt $olt): array
            {
                return [];
            }

            public function uplinkInterfaceNames(SnmpOlt $olt): array
            {
                return $this->names;
            }
        });
    }

    /**
     * Kasus nyata (13 Okt 2026): port 10GE kartu SMXA C320 bernama `xgei_1/4/2`, tapi NMS menebak
     * `gei_1/4/2` dari tipe kartu → ketiga perintah CLI ditolak `%Error 20202`. Tautan lama ke nama
     * tebakan kini dibetulkan lewat ifName dan halaman dialihkan ke nama aslinya.
     */
    public function test_port_detail_refresh_corrects_guessed_uplink_name(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt(['name' => 'BMKV-C320', 'vendor' => 'ZTE C320']);

        $this->fakeUplinkNames(['4/1' => 'gei_1/4/1', '4/2' => 'xgei_1/4/2', '4/3' => 'gei_1/4/3']);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $expected = "show interface port-status xgei_1/4/2\nshow vlan port xgei_1/4/2\nshow interface optical-module-info xgei_1/4/2";
                if ($script !== $expected) {
                    throw new RuntimeException('Unexpected CLI command: '.$script);
                }

                return [
                    'ok' => true,
                    'error' => null,
                    'output' => <<<'OUT'
> show interface port-status xgei_1/4/2
--------------------------------------------------------------------------------
     Port      hybrid  Native Negotiation  Speed  Duplex Flow-   Admin      Link
               Status  VLAN     auto       (Mbps)        Ctrl    Status
--------------------------------------------------------------------------------
xgei_1/4/2     optical  1       disable    10000   full  disable activate   up
BMKV-C320#
> show vlan port xgei_1/4/2
TaggedVlan:
100
BMKV-C320#
> show interface optical-module-info xgei_1/4/2
 No optical module present.
BMKV-C320#
OUT,
                ];
            }
        });

        $response = $this->actingAs($user)->post(route('smartolt.port.refresh', $olt), [
            'interface' => 'gei_1/4/2',
        ]);

        $response->assertRedirect(route('smartolt.port.detail', ['olt' => $olt->id, 'interface' => 'xgei_1/4/2']));
        $response->assertSessionHas('success');

        $row = SmartOltInterfaceStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('interface', 'xgei_1/4/2')
            ->firstOrFail();

        $this->assertSame(4, $row->slot);
        $this->assertSame(2, $row->port);
        $this->assertSame(10000, $row->speed_mbps);
        $this->assertSame('up', $row->link_status);
        $this->assertFalse(SmartOltInterfaceStatus::query()->where('interface', 'gei_1/4/2')->exists());
    }

    /** Refresh Hardware: inventaris uplink memakai nama ifName, termasuk HUVQ campuran xgei/gei. */
    public function test_hardware_refresh_uses_snmp_uplink_names(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $this->fakeUplinkNames([
            '20/1' => 'xgei_1/20/1', '20/2' => 'xgei_1/20/2', '20/3' => 'gei_1/20/3', '20/4' => 'gei_1/20/4',
        ]);

        $executor = new class extends ZteCliProvisioningExecutor
        {
            public array $scripts = [];

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $this->scripts[] = $script;

                if ($script === 'show card') {
                    return ['ok' => true, 'error' => null, 'output' => <<<'OUT'
Rack Shelf Slot CfgType RealType Port  HardVer SoftVer         Status
-------------------------------------------------------------------------------
1    1     20   HUVQ    HUVQ     4     V1.0.0  V2.1.0          INSERVICE
OUT];
                }

                $output = '';
                foreach (['xgei_1/20/1' => 10000, 'xgei_1/20/2' => 10000, 'gei_1/20/3' => 1000, 'gei_1/20/4' => 1000] as $iface => $speed) {
                    $output .= "> show interface port-status {$iface}\n"
                        .str_pad($iface, 15)."optical  1       disable    {$speed}   full  disable activate   up\n"
                        ."BMKV-C300#\n";
                }

                return ['ok' => true, 'error' => null, 'output' => $output];
            }
        };
        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);

        $this->actingAs($user)->post(route('smartolt.hardware.refresh', $olt))
            ->assertRedirect(route('smartolt.detail', $olt));

        $this->assertStringContainsString('show interface port-status gei_1/20/3', $executor->scripts[1]);
        $this->assertStringNotContainsString('xgei_1/20/3', $executor->scripts[1]);

        $this->assertSame(
            ['gei_1/20/3', 'gei_1/20/4', 'xgei_1/20/1', 'xgei_1/20/2'],
            SmartOltInterfaceStatus::query()->where('snmp_olt_id', $olt->id)->orderBy('interface')->pluck('interface')->all(),
        );

        $this->actingAs($user)->get(route('smartolt.detail', $olt))
            ->assertInertia(fn ($page) => $page
                ->where('interfaces.2.interface', 'gei_1/20/3')
                ->where('interfaces.2.interface_type', 'uplink')
                ->where('interfaces.2.slot', 20)
                ->where('interfaces.2.port', 3)
            );
    }

    public function test_port_detail_reads_gpon_from_snapshot_without_cli(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt([
            'last_test_result' => [
                'ports' => [
                    ['name' => 'gpon-olt_1/2/1', 'slot' => 2, 'port' => 1, 'oper_status' => 'up'],
                ],
            ],
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                throw new RuntimeException('CLI should not run while rendering port detail.');
            }
        });

        $response = $this->actingAs($user)->get(route('smartolt.port.detail', ['olt' => $olt->id, 'interface' => 'gpon-olt_1/2/1']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SmartOlt/PortDetail')
            ->where('type', 'gpon')
            ->where('detail.interface', 'gpon-olt_1/2/1')
            ->where('detail.interface_type', 'gpon')
        );
    }

    public function test_gpon_interface_refresh_persists_show_interface_output(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        SmartOltCardStatus::create([
            'snmp_olt_id' => $olt->id,
            'rack' => 1,
            'shelf' => 1,
            'slot' => 2,
            'cfg_type' => 'GTGH',
            'real_type' => 'GTGHG',
            'port_count' => 16,
            'status' => 'INSERVICE',
            'refreshed_at' => now(),
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $expected = "show interface gpon-olt_1/2/1\nshow interface optical-module-info gpon-olt_1/2/1";
                if ($script !== $expected) {
                    throw new RuntimeException('Unexpected CLI command: '.$script);
                }

                return [
                    'ok' => true,
                    'error' => null,
                    'output' => <<<'OUT'
> show interface gpon-olt_1/2/1
gpon-olt_1/2/1 is activate,line protocol is up.
  Description is none.
  The port is activate.
  The port has 128 onus, the number of registered onus is 25.

OLT statistic:
   Input rate :            6588950 Bps            21141 pps
   Output rate:           51269467 Bps            41264 pps
   Input Instantaneous bandwidth throughput : 4.2%
   Output Instantaneous bandwidth throughput: 16.5%
   Input Average bandwidth throughput : 2.9%
   Output Average bandwidth throughput: 14.2%
Interface peak rate:
   Input peak rate :           52025826 Bps            70288 pps
   Output peak rate:          168144968 Bps          1266051 pps
Total statistic:
 Input :
   PassPackets   :144990580826         DropPackets   :3942671
   PassBytes     :33105326359834       UnicastsPkts  :144988266805
 Output :
   PassPackets   :264027625452         DropPackets   :246696252
   PassBytes     :319465172556729      UnicastsPkts  :263943449926
BMKV-C300#
> show interface optical-module-info gpon-olt_1/2/1
  Optical module information:gpon-olt_1/2/1
  Basic-info:
   Vendor-Name    : OEM                      Vendor-Pn      : TS-GPTD3425-20DC
   Vendor-Sn      : 202411110081             Module-Type    : SFP/SFP+
   Wavelength     : 1490      (nm)           Connector      : SC
   Trans-Distance : 20(km)
  Diagnostic-info:
   RxPower        : N/A                      TxPower      : 11.026(dbm)
   TxBias-Current : 23.030    (mA)           Laser-Rate   : 25(100Mb/s)
   Temperature    : 41.406    (c)            Supply-Vol   : 3.226(v)
OUT,
                ];
            }
        });

        $response = $this->actingAs($user)->post(route('smartolt.port.refresh', $olt), [
            'interface' => 'gpon-olt_1/2/1',
        ]);

        $response->assertRedirect(route('smartolt.port.detail', ['olt' => $olt->id, 'interface' => 'gpon-olt_1/2/1']));

        $row = SmartOltInterfaceStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('interface', 'gpon-olt_1/2/1')
            ->firstOrFail();

        $this->assertSame('gpon', $row->interface_type);
        $this->assertSame('GTGH', $row->card_type);
        $this->assertSame('activate', $row->admin_status);
        $this->assertSame('up', $row->link_status);
        $this->assertSame(128, $row->onu_capacity);
        $this->assertSame(25, $row->registered_onu_count);
        $this->assertSame(6588950, $row->input_bps);
        $this->assertSame(51269467, $row->output_bps);
        $this->assertSame(4.2, $row->input_throughput_percent);
        $this->assertSame(16.5, $row->output_throughput_percent);
        $this->assertSame(52025826, $row->input_peak_bps);
        $this->assertSame(168144968, $row->output_peak_bps);
        $this->assertSame(144990580826, $row->gpon_counters['input']['PassPackets']);
        $this->assertSame(319465172556729, $row->gpon_counters['output']['PassBytes']);
        $this->assertSame('OEM', $row->optical_vendor_name);
        $this->assertSame('TS-GPTD3425-20DC', $row->optical_vendor_pn);
        $this->assertSame(11.026, $row->tx_power_dbm);
        $this->assertNull($row->rx_power_dbm);
        $this->assertSame(41.406, $row->temperature_c);
    }
}
