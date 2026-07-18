<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use PHPUnit\Framework\TestCase;

class C600OnuNameStateTest extends TestCase
{
    private function client(): OltSnmpClient
    {
        // Mock the SNMP walk per column: an online/enabled ONU (id 1) and a
        // disabled ONU whose last state was LOS (id 3), both on PON ifIndex 285278977.
        return new class extends OltSnmpClient
        {
            public function walk(SnmpOlt $olt, string $oid): array
            {
                $cols = [
                    '20.2.1.2.1.8' => [1 => 'F641', 3 => 'HG8145X6-10'],                        // type
                    '20.2.1.2.1.3' => [1 => '5A 54 45 47 00 8E EB 08', 3 => '48 57 54 43 89 E6 4C A6'], // serial
                    '10.2.3.3.1.2' => [1 => 'MARIA ESMIRNA LIZARDO', 3 => 'JOSE DE LA ROSA'],   // name
                    '10.2.3.8.1.1' => [1 => 1, 3 => 2],                                          // admin: enable / disable
                    '10.2.3.8.1.4' => [1 => 4, 3 => 2],                                          // phase: Working / LOS
                ];

                foreach ($cols as $frag => $vals) {
                    if (str_contains($oid, $frag)) {
                        $out = [];
                        foreach ($vals as $onuId => $v) {
                            // walk() normalizes rows via ltrim('.') → keys have no leading dot.
                            $out["{$oid}.285278977.{$onuId}"] = (string) $v;
                        }

                        return $out;
                    }
                }

                return [];
            }
        };
    }

    public function test_c600_registered_onus_carry_name_admin_and_rich_phase(): void
    {
        $olt = new SnmpOlt(['vendor' => 'ZTE C600', 'name' => 'LAS GALERAS']);
        $ports = [['if_index' => 285278977, 'slot' => 3, 'port' => 1]];

        $onus = $this->client()->registeredOnus($olt, $ports);
        $this->assertCount(2, $onus);
        [$a, $b] = $onus;

        // Online, enabled ONU — real customer name now readable via SNMP (CLI masks it).
        $this->assertSame(1, $a['onu_id']);
        $this->assertSame('MARIA ESMIRNA LIZARDO', $a['name']);
        $this->assertSame('ZTEG008EEB08', $a['serial_number']);
        $this->assertSame('active', $a['admin_state']);
        $this->assertSame('Working', $a['phase_state']);
        $this->assertTrue($a['online']);

        // Disabled ONU whose state is LOS — admin + rich down reason now surfaced.
        $this->assertSame(3, $b['onu_id']);
        $this->assertSame('JOSE DE LA ROSA', $b['name']);
        $this->assertSame('HWTC89E64CA6', $b['serial_number']);
        $this->assertSame('disabled', $b['admin_state']);
        $this->assertSame('LOS', $b['phase_state']);
        $this->assertFalse($b['online']);
    }
}
