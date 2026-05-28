<?php

namespace Tests\Unit;

use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuDetailService;
use PHPUnit\Framework\TestCase;

class ZteOnuDetailTest extends TestCase
{
    private function parser(): ZteOnuDetailService
    {
        return new ZteOnuDetailService($this->createMock(ZteCliProvisioningExecutor::class));
    }

    public function test_parses_detail_info_into_groups(): void
    {
        $raw = <<<'RAW'
gpon-onu_1/2/1:3 detail info:
  SN                 : ZTEGC1234567
  Name               : Server Semampir
  Type               : ALL-ONT
  Vendor-Id          : ZTEG
  Online Duration(s) : 3600
  Phase state        : working
  Admin state        : enable
  RX power(dbm)      : -18.21
  Distance(m)        : 1234
  Temperature(c)     : 45
  Last down cause    : LOS

            OLT                  ONU              Attenuation
 -------------------------------------------------------------
 up      Rx :-20.850(dbm)      Tx:2.868(dbm)        23.718(dB)
 down    Tx :4.230(dbm)        Rx:-18.210(dbm)      22.440(dB)
RAW;

        $groups = $this->parser()->parse($raw);

        $this->assertSame('ZTEGC1234567', $groups['identity']['sn']);
        $this->assertSame('Server Semampir', $groups['identity']['name']);
        $this->assertSame('ALL-ONT', $groups['identity']['type']);
        $this->assertSame('ZTEG', $groups['identity']['vendor_id']);

        $this->assertSame('3600', $groups['state']['online_duration']);
        $this->assertSame('working', $groups['state']['phase_state']);
        $this->assertSame('enable', $groups['state']['admin_state']);

        $this->assertSame('-18.21', $groups['optical']['rx_power_dbm']);
        $this->assertSame('1234', $groups['optical']['distance_m']);
        $this->assertSame('45', $groups['optical']['temperature_c']);

        // Supplemented from attenuation table.
        $this->assertSame('2.868', $groups['optical']['onu_tx_dbm']);
        $this->assertSame('23.718', $groups['optical']['att_up_db']);
        $this->assertSame('-18.210', $groups['optical']['onu_rx_dbm']);
        $this->assertSame('22.440', $groups['optical']['att_down_db']);

        $this->assertSame('LOS', $groups['last_event']['last_down_cause']);

        // Session-history rows must not pollute the "all" map.
        $this->assertArrayNotHasKey('1_2026_05_20_10', $groups['all']);
    }

    public function test_session_history_fills_last_event_when_missing(): void
    {
        $raw = <<<'RAW'
gpon-onu_1/1/1:5 detail info:
  SN   : ZTEGC7654321
  Name : Pelanggan A

idx  Authpass Time          OfflineTime           Cause
1    2026-05-20 10:23:45    2026-05-22 03:11:00   LOS
2    2026-05-22 04:15:30    0000-00-00 00:00:00   -
RAW;

        $groups = $this->parser()->parse($raw);

        $this->assertSame('2026-05-22 04:15:30', $groups['last_event']['last_up_time']);
        $this->assertSame('2026-05-22 03:11:00', $groups['last_event']['last_down_time']);
        $this->assertSame('LOS', $groups['last_event']['last_down_cause']);
    }
}
