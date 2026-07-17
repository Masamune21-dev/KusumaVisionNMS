<?php

namespace Tests\Unit;

use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuRxPowerService;
use PHPUnit\Framework\TestCase;

class ZteOnuRxPowerTest extends TestCase
{
    private function service(): ZteOnuRxPowerService
    {
        return new ZteOnuRxPowerService($this->createMock(ZteCliProvisioningExecutor::class));
    }

    public function test_parses_c300_onu_rx_lines(): void
    {
        $powers = $this->service()->parse(
            "gpon-onu_1/3/1:5      -20.53(dbm)\n".
            "gpon-onu_1/3/1:6      -18.20 (dbm)\n"
        );

        $this->assertSame(3, $powers[5]['slot']);
        $this->assertSame(1, $powers[5]['port']);
        $this->assertSame(5, $powers[5]['onu_id']);
        $this->assertSame(-20.53, $powers[5]['rx_power_dbm']);
        $this->assertSame(-18.2, $powers[6]['rx_power_dbm']);
    }

    public function test_parses_c600_onu_rx_lines_with_gpon_onu_spelling(): void
    {
        // C600 mengeja `gpon_onu-` (3-tier `1/{slot}/{port}:{id}`), bukan 4-tier seperti
        // asumsi lama. Parser harus tetap menangkap slot/port/onu & nilai dBm-nya.
        $powers = $this->service()->parse(
            "gpon_onu-1/3/1:5      -21.55(dbm)\n".
            "gpon_onu-1/3/1:11     -15.48(dbm)\n",
            true,
        );

        $this->assertArrayHasKey(5, $powers);
        $this->assertSame(3, $powers[5]['slot']);
        $this->assertSame(1, $powers[5]['port']);
        $this->assertSame(5, $powers[5]['onu_id']);
        $this->assertSame(-21.55, $powers[5]['rx_power_dbm']);
        $this->assertSame(-15.48, $powers[11]['rx_power_dbm']);
        $this->assertSame('-15.480 dBm', $powers[11]['rx_power_label']);
    }
}
