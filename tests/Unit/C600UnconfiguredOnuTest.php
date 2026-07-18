<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use PHPUnit\Framework\TestCase;

class C600UnconfiguredOnuTest extends TestCase
{
    /**
     * C600/TITAN unconfigured discovery lewat SNMP: kolom serial `.2` dari tabel
     * `.1082.500.2.2.11.2.1`, index {PON-ifIndex}.{entry}, serial 8-byte (4 ASCII + 4 raw).
     * Diverifikasi live: HWTCC62B52AF di gpon_olt-1/5/16 (ifIndex 285279504).
     */
    public function test_c600_unconfigured_discovery_decodes_serial_and_pon_port(): void
    {
        $client = new class extends OltSnmpClient
        {
            public function walk(SnmpOlt $olt, string $oid): array
            {
                if (str_contains($oid, '1082.500.2.2.11.2.1.2')) {
                    return ['.'.ltrim($oid, '.').'.285279504.1' => '48 57 54 43 C6 2B 52 AF'];
                }

                return [];
            }
        };

        $olt = new SnmpOlt(['vendor' => 'ZTE C600', 'name' => 'LAS GALERAS']);
        $rows = $client->unconfiguredOnus($olt);

        $this->assertCount(1, $rows);
        $this->assertSame('HWTCC62B52AF', $rows[0]['serial_number']);
        $this->assertSame(285279504, $rows[0]['if_index']);
        $this->assertSame(5, $rows[0]['slot']);  // ifIndex 0x11010510 → (>>8)&0xFF
        $this->assertSame(16, $rows[0]['port']); //                   →  &0xFF
        $this->assertSame('gpon_onu-1/5/16:1', $rows[0]['port_alias']);
    }

    public function test_non_c600_does_not_use_c600_unconfigured_oid(): void
    {
        // OLT non-C600 memakai OID uncfg ZTE C300/C320 — bukan tabel C600 — jadi walk
        // OID C600 tak pernah dipanggil dan hasilnya kosong.
        $client = new class extends OltSnmpClient
        {
            public function walk(SnmpOlt $olt, string $oid): array
            {
                return str_contains($oid, '1082.500.2.2.11.2.1.2')
                    ? ['x.1.1' => '48 57 54 43 C6 2B 52 AF']
                    : [];
            }
        };

        $olt = new SnmpOlt(['vendor' => 'ZTE C320', 'name' => 'EL VALLE']);

        $this->assertSame([], $client->unconfiguredOnus($olt));
    }
}
