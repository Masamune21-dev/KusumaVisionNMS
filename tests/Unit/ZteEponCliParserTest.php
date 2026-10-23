<?php

namespace Tests\Unit;

use App\Services\Zte\Epon\ZteEponCliParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixture parser EPON ZTE C300/C320. Belum ada OLT ETG untuk diuji (lihat App\Support\ZteEpon), jadi
 * fixture diambil dari keluaran ASLI pihak lain, disebut sumbernya per test:
 *  - [forum] operator C300 V1.2.5P3 (kartu ETGO), local.com.ua topik 88003, Jun 2016;
 *  - [manual] panduan awal C300 EPON berbahasa Tionghoa (V1.2.3P3, kartu ETGO/ETGOD), renrendoc 87264815;
 *  - [s4lfanet] contoh running-config di komentar kode repo MIT s4lfanet/nms-ztec320 (C320 V2.1).
 * Baris `show card` GPON adalah keluaran asli OLT C300 berkartu GTGH (Okt 2026) sebagai pembanding.
 */
class ZteEponCliParserTest extends TestCase
{
    private ZteEponCliParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ZteEponCliParser;
    }

    public function test_epon_cards_from_manual_show_card_ignoring_gpon_and_control_cards(): void
    {
        // [manual] + baris GPON/kartu offline dari OLT C300 berkartu GTG.
        $output = <<<'TXT'
ZXAN(config)#show card
Rack Shelf Slot CfgType RealType Port  HardVer SoftVer         Status
-------------------------------------------------------------------------------
1    1     0    PRWG    PRWG     0                             INSERVICE
1    1     1    PRWG    PRWG     0                             NOPOWER
1    1     2    ETGO    ETGOD    8     091201  V1.2.3P3        INSERVICE
1    1     3    GTGO    GTGOA    8     080802  V1.2.3P3        INSERVICE
1    1     5    ETGH             16                            OFFLINE
1    1     10   SCXM    SCXM     0     090700  V1.2.3P3        STANDBY
  1    1     4    SMXA             3                             OFFLINE
TXT;

        $cards = $this->parser->eponCards($output);

        $this->assertSame([
            ['slot' => 2, 'cfg_type' => 'ETGO', 'real_type' => 'ETGOD', 'ports' => 8, 'status' => 'INSERVICE'],
            ['slot' => 5, 'cfg_type' => 'ETGH', 'real_type' => null, 'ports' => 16, 'status' => 'OFFLINE'],
        ], $cards);
    }

    public function test_gpon_only_chassis_has_no_epon_cards(): void
    {
        // Keluaran asli `show card` OLT C300 berkartu GTGH saja, Okt 2026.
        $output = <<<'TXT'
  1    1     0    PRWG    PRWG     0     V1.0.0                  INSERVICE
  1    1     2    GTGH    GTGHG    16    V1.0.0  V2.1.0          INSERVICE
  1    1     4    GTGH    GTGHK    16    V1.0.0  V2.1.0          INSERVICE
  1    1     11   SCXN    SCXN     4     V1.0.0  V2.1.0          INSERVICE
  1    1     19   HUVQ    HUVQ     4     V1.0.0  V2.1.0          INSERVICE
TXT;

        $this->assertSame([], $this->parser->eponCards($output));
    }

    public function test_onu_states_from_forum_output(): void
    {
        // [forum] `sho epon onu sta` — ONU offline melapor MAC 0000.0000.0000.
        $output = <<<'TXT'
ZXAN#sho epon onu sta
OnuIndex               OnlineStatus  OamStatus   RegMac
--------------------------------------------------------------------
epon-onu_1/4/8:1       Online       complete     1c87.7912.0c39
epon-onu_1/4/8:2       Offline      idle         0000.0000.0000
epon-onu_1/4/8:3       Offline      idle         0000.0000.0000
ONU Number: 1/3
TXT;

        $rows = $this->parser->onuStates($output);

        $this->assertCount(3, $rows);
        $this->assertSame(['slot' => 4, 'port' => 8, 'onu_id' => 1, 'status' => 'Online', 'oam_status' => 'complete', 'mac' => '1C:87:79:12:0C:39'], $rows[0]);
        $this->assertSame('Offline', $rows[1]['status']);
        $this->assertNull($rows[1]['mac']);
    }

    public function test_status_fields_map_to_alarm_vocabulary(): void
    {
        $this->assertSame(['online' => true, 'phase_state' => 'Online', 'last_down_cause' => null], $this->parser->statusFields('Online'));
        $this->assertFalse($this->parser->statusFields('Offline')['online']);
        $this->assertSame('LOS', $this->parser->statusFields('LOS')['phase_state']);
        $this->assertSame('DyingGasp', $this->parser->statusFields('DyingGasp')['phase_state']);
        $this->assertSame('DyingGasp', $this->parser->statusFields('Power-off')['last_down_cause']);
    }

    public function test_registered_onus_from_forum_and_s4lfanet_running_config(): void
    {
        // [forum] V1.2.5P3 lalu [s4lfanet] V2.1; blok GPON ikut supaya terbukti tidak terbaca.
        $output = <<<'TXT'
interface epon-olt_1/4/8
  p2p mode group
  auto-learning enable
  onu 1 type universalOnuType mac 1c87.7912.0c39 ip-cfg static
  onu 2 type universalOnuType mac c07e.405b.1dde ip-cfg static
!
interface gpon-olt_1/2/1
  onu 1 type ALL-ONT sn ZTEGC0800001
!
interface epon-olt_1/2/2
  no shutdown
  linktrap disable
  p2p mode group
  onu 1 type ALL-EPON mac 543e.6496.f469 ip-cfg static
!
TXT;

        $result = $this->parser->registeredOnus($output);

        $this->assertSame(['4_8', '2_2'], array_keys($result));
        $this->assertSame(['onu_id' => 2, 'type_name' => 'universalOnuType', 'mac' => 'C0:7E:40:5B:1D:DE'], $result['4_8'][2]);
        $this->assertSame('ALL-EPON', $result['2_2'][1]['type_name']);
    }

    public function test_onu_configs_read_property_description_sla_and_vlans(): void
    {
        // [s4lfanet] V2.1 (property description + service-port) dan [forum]/[manual] V1.x.
        $output = <<<'TXT'
interface epon-onu_1/2/2:1
  property description $$Pelanggan Uji$$Jl. Contoh 1
  ems-autocfg-request disable
  sla-profile EPON vport 1
  encrypt direction downstream  enable  vport 1
  service-port 1 vport 1 user-vlan 110 vlan 110
!
interface epon-onu_1/2/1:1
  sla-profile 10M
  switchport vlan 200 tag
!
interface epon-onu_1/4/8:1
  admin enable
  ems-autocfg-request disable
  switchport mode hybrid vport 1
!
TXT;

        $configs = $this->parser->onuConfigs($output);

        $this->assertSame(['name' => 'Pelanggan Uji', 'description' => 'Jl. Contoh 1', 'sla_profile' => 'EPON', 'vlans' => [110]], $configs['2_2_1']);
        $this->assertSame(['name' => null, 'description' => null, 'sla_profile' => '10M', 'vlans' => [200]], $configs['2_1_1']);
        $this->assertSame([], $configs['4_8_1']['vlans']);
    }

    public function test_split_property_description_variants(): void
    {
        $this->assertSame(['Nama', 'Desk'], $this->parser->splitPropertyDescription('$$Nama$$Desk'));
        $this->assertSame(['Nama', null], $this->parser->splitPropertyDescription('$$Nama$$'));
        $this->assertSame(['Polos saja', null], $this->parser->splitPropertyDescription('Polos saja'));
        $this->assertSame([null, null], $this->parser->splitPropertyDescription('$$$$'));
    }

    public function test_unauthenticated_block_from_manual(): void
    {
        // [manual] `show onu unauthentication` di port epon-olt_1/2/1 (dua ONU, salah satunya tanpa SN).
        $output = <<<'TXT'
ZXAN#show onu unauthentication epon-olt_1/2/1
Onu Interface    : epon-onu_1/2/1:1
Onu Model        : RL-4W
MAC Address      : 1c18.4a0d.d30f
SN               :
LOID             :
LOID_PSW         :
Online State     : authentication deny
ExtOam State     : complete
Software Version : 6151
Hardware Version : v1.0_01
RegTime          : 2015/07/22 10:43:06

Onu Interface    : epon-onu_1/2/1:2
Onu Model        : RL-4FC
MAC Address      : 1c18.4a0f.e563
SN               :
Online State     : authentication deny
TXT;

        $rows = $this->parser->unauthenticated($output);

        $this->assertCount(2, $rows);
        $this->assertSame([
            'slot' => 2, 'port' => 1, 'model' => 'RL-4W', 'serial' => null, 'loid' => null,
            'state' => 'authentication deny', 'firmware' => '6151', 'mac' => '1C:18:4A:0D:D3:0F',
        ], $rows[0]);
        $this->assertSame('1C:18:4A:0F:E5:63', $rows[1]['mac']);
    }

    public function test_unauthenticated_table_form_with_mac(): void
    {
        $output = "epon-olt_1/2/8    HWTC-245C    543e.6496.f469\nepon-olt_1/2/1    F670LV9.0    N/A\n";

        $rows = $this->parser->unauthenticated($output);

        $this->assertCount(1, $rows);
        $this->assertSame(['slot' => 2, 'port' => 8, 'mac' => '54:3E:64:96:F4:69', 'model' => 'HWTC-245C'], array_intersect_key($rows[0], array_flip(['slot', 'port', 'mac', 'model'])));
    }

    public function test_rx_powers_use_gpon_onu_rx_layout(): void
    {
        $output = "epon-onu_1/2/1:1      -20.530(dbm)\nepon-onu_1/2/1:2      N/A\nepon-onu_1/2/1:3      -80.000(dbm)\n";

        $this->assertSame(['2_1_1' => -20.53], $this->parser->onuRxPowers($output));
    }

    public function test_epon_onu_types_from_manual(): void
    {
        $output = <<<'TXT'
ONU type name:  ZTE-EPON
PON type:       epon
Description:    804W
ONU type name:  ALL-ONT
PON type:       gpon
TXT;

        $this->assertSame(['ZTE-EPON'], $this->parser->eponOnuTypes($output));
    }

    public function test_split_by_command_uses_executor_markers(): void
    {
        $output = "login\n> show card\n  1 1 2 ETGO ETGOD 8 V1 V2 INSERVICE\nZXAN#\n> show epon onu state\nepon-onu_1/2/1:1 Online complete 1c18.4a0d.d30f\nZXAN#\n";

        $chunks = $this->parser->splitByCommand($output);

        $this->assertSame(['show card', 'show epon onu state'], array_column($chunks, 'command'));
        $this->assertStringContainsString('ETGO', $this->parser->outputOf($output, 'show card'));
        $this->assertStringNotContainsString('ETGO', $this->parser->outputOf($output, 'show epon onu state'));
    }
}
