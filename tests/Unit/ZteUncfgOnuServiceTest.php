<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteUncfgOnuService;
use PHPUnit\Framework\TestCase;

class ZteUncfgOnuServiceTest extends TestCase
{
    /** Output nyata `show gpon onu uncfg` dari OLT-C320-PATI (06 Jul 2026). */
    private const REAL_OUTPUT = <<<'CLI'
LO.Berkah-Pati.v2#
> terminal length 0

LO.Berkah-Pati.v2#
> show gpon onu uncfg

OnuIndex                 Sn                  State
---------------------------------------------------------------------
gpon-onu_1/2/2:1         ZTEGCD7D2FD6        unknown
LO.Berkah-Pati.v2#
CLI;

    private function serviceReturning(array $result): ZteUncfgOnuService
    {
        $executor = $this->createMock(ZteCliProvisioningExecutor::class);
        $executor->method('execute')->willReturn($result);

        return new ZteUncfgOnuService($executor);
    }

    public function test_parses_real_c320_output(): void
    {
        $service = $this->serviceReturning(['ok' => true, 'output' => self::REAL_OUTPUT, 'error' => null]);

        $result = $service->fetch(new SnmpOlt);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertCount(1, $result['onus']);
        $this->assertSame([
            'interface' => 'gpon-onu_1/2/2:1',
            'slot' => 2,
            'port' => 2,
            'seq' => 1,
            'serial_number' => 'ZTEGCD7D2FD6',
            'state' => 'unknown',
        ], $result['onus'][0]);
    }

    public function test_empty_table_means_no_uncfg_onus(): void
    {
        $output = "> show gpon onu uncfg\n\nOnuIndex                 Sn                  State\n"
            ."---------------------------------------------------------------------\nZXAN#";
        $service = $this->serviceReturning(['ok' => true, 'output' => $output, 'error' => null]);

        $result = $service->fetch(new SnmpOlt);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['onus']);
    }

    public function test_rows_are_deduplicated_and_sorted_by_slot_port(): void
    {
        $output = implode("\n", [
            'gpon-onu_1/3/4:1         ZTEGZZ000002        unknown',
            'gpon-onu_1/1/8:2         ZTEGAA000001        unknown',
            'gpon-onu_1/3/4:1         ZTEGZZ000002        unknown',
        ]);
        $service = $this->serviceReturning(['ok' => true, 'output' => $output, 'error' => null]);

        $onus = $service->fetch(new SnmpOlt)['onus'];

        $this->assertCount(2, $onus);
        $this->assertSame(['ZTEGAA000001', 'ZTEGZZ000002'], array_column($onus, 'serial_number'));
    }

    public function test_cli_failure_is_propagated(): void
    {
        $service = $this->serviceReturning(['ok' => false, 'output' => '', 'error' => 'Invalid input']);

        $result = $service->fetch(new SnmpOlt);

        $this->assertFalse($result['ok']);
        $this->assertSame('Invalid input', $result['error']);
        $this->assertSame([], $result['onus']);
    }
}
