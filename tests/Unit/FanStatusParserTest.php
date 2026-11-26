<?php

namespace Tests\Unit;

use App\Services\Olt\OltFanStatusService;
use Tests\TestCase;

class FanStatusParserTest extends TestCase
{
    private function base(): string
    {
        return '1.3.6.1.4.1.3902.1015.2.1.3.10.10';
    }

    public function test_parses_c300_c320_snmp_rows(): void
    {
        $base = $this->base();
        $table = $base.'.10.1';

        $rows = [
            $base.'.1.0' => 'INTEGER: 1',
            $base.'.6.0' => 'INTEGER: 20',
            $base.'.7.0' => 'INTEGER: 36',
            $base.'.8.0' => 'INTEGER: 44',
            $base.'.9.0' => 'INTEGER: 51',
            $base.'.11.0' => 'INTEGER: 20',
            $base.'.12.0' => 'INTEGER: 35',
            $base.'.13.0' => 'INTEGER: 50',
            $base.'.14.0' => 'INTEGER: 75',
            $table.'.2.1' => 'INTEGER: 3',
            $table.'.3.1' => 'INTEGER: 3',
            $table.'.4.1' => 'INTEGER: 1',
            $table.'.5.1' => 'INTEGER: 1',
            $table.'.6.1' => 'INTEGER: 1',
            $table.'.7.1' => 'INTEGER: 3246',
            $table.'.2.2' => 'INTEGER: 3',
            $table.'.3.2' => 'INTEGER: 2',
            $table.'.4.2' => 'INTEGER: 1',
            $table.'.5.2' => 'INTEGER: 2',
            $table.'.6.2' => 'INTEGER: 1',
            $table.'.7.2' => 'INTEGER: 3156',
        ];

        $data = (new OltFanStatusService(
            $this->createMock(\App\Services\Snmp\OltSnmpClient::class),
            $this->createMock(\App\Services\ZteCliProvisioningExecutor::class),
        ))->parseSnmpRows($rows, 39);

        $this->assertSame('snmp', $data['source']);
        $this->assertSame('auto', $data['mode']);
        $this->assertSame(1, $data['mode_code']);
        $this->assertSame(39, $data['temp_c']);
        $this->assertSame([20, 36, 44, 51], $data['thresholds']);
        $this->assertSame([20, 35, 50, 75], $data['percents']);
        $this->assertCount(2, $data['fans']);

        $this->assertSame(1, $data['fans'][0]['fan_index']);
        $this->assertSame('normal', $data['fans'][0]['status']);
        $this->assertSame(3, $data['fans'][0]['level']);
        $this->assertSame(3246, $data['fans'][0]['speed_rpm']);

        // Fan 2 dilaporkan operStatus 2 → dianggap gangguan, bukan normal.
        $this->assertSame('abnormal', $data['fans'][1]['status']);
        $this->assertSame(3156, $data['fans'][1]['speed_rpm']);
    }

    public function test_parses_c600_show_fan_output(): void
    {
        $output = <<<'OUT'
[shelf 1 LCC]:
Fan speed mini level: 1
Group 0: online
Fan speed mode: auto
Fan       name: FAN
Index      Status      Speed
  0    work normally   44%
  1    work normally   45%
  2    work normally   44%
  3    work normally   45%
  4    work normally   44%
  5    work normally   45%

ZXAN#exit
OUT;

        $data = (new OltFanStatusService(
            $this->createMock(\App\Services\Snmp\OltSnmpClient::class),
            $this->createMock(\App\Services\ZteCliProvisioningExecutor::class),
        ))->parseC600Cli($output);

        $this->assertSame('cli', $data['source']);
        $this->assertSame('auto', $data['mode']);
        $this->assertSame(1, $data['min_level']);
        $this->assertNull($data['temp_c']);
        $this->assertCount(6, $data['fans']);
        $this->assertSame(0, $data['fans'][0]['fan_index']);
        $this->assertSame(44, $data['fans'][0]['speed_percent']);
        $this->assertNull($data['fans'][0]['speed_rpm']);
        $this->assertSame('normal', $data['fans'][0]['status']);
    }

    public function test_c600_parser_returns_empty_fans_when_output_has_no_table(): void
    {
        $data = (new OltFanStatusService(
            $this->createMock(\App\Services\Snmp\OltSnmpClient::class),
            $this->createMock(\App\Services\ZteCliProvisioningExecutor::class),
        ))->parseC600Cli('Fan speed mode: auto');

        $this->assertSame([], $data['fans']);
    }
}
