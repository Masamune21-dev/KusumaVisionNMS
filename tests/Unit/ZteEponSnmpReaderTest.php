<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Services\Zte\Epon\ZteEponSnmpReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Indeks & bentuk nilai tabel EPON ZTE yang dibaca dari C300 V2.1 berkartu ETGO (24 Okt 2026).
 * Angka indeks di bawah adalah indeks asli dari walk OLT itu; MAC fiktif (rentang dokumentasi).
 */
class ZteEponSnmpReaderTest extends TestCase
{
    public function test_decodes_real_onu_indexes(): void
    {
        $this->assertSame(['slot' => 8, 'port' => 1, 'onu_id' => 1], ZteEponSnmpReader::decodeOnuIndex(809500928));
        $this->assertSame(['slot' => 8, 'port' => 1, 'onu_id' => 3], ZteEponSnmpReader::decodeOnuIndex(809501440));
        $this->assertSame(['slot' => 8, 'port' => 4, 'onu_id' => 1], ZteEponSnmpReader::decodeOnuIndex(809697536));
        $this->assertSame(809697536, ZteEponSnmpReader::encodeOnuIndex(8, 4, 1));
    }

    public function test_rejects_indexes_that_are_not_epon_onus(): void
    {
        // ifIndex port GPON gaya `.1012` (0x1002…) dan indeks dengan byte terendah terisi.
        $this->assertNull(ZteEponSnmpReader::decodeOnuIndex(268566784));
        $this->assertNull(ZteEponSnmpReader::decodeOnuIndex(809500928 + 1));
        $this->assertNull(ZteEponSnmpReader::decodeOnuIndex(ZteEponSnmpReader::encodeOnuIndex(8, 1, 1) & ~0xFF00));
    }

    public function test_mac_from_raw_octets_or_hex_text(): void
    {
        $this->assertSame('00:00:5E:00:53:01', ZteEponSnmpReader::macFromOctets(hex2bin('00005e005301')));
        $this->assertSame('00:00:5E:00:53:01', ZteEponSnmpReader::macFromOctets('00 00 5E 00 53 01'));
        // MAC yang semua bytenya kebetulan tercetak tetap 6 byte mentah, bukan teks.
        $this->assertSame('41:42:43:44:45:46', ZteEponSnmpReader::macFromOctets('ABCDEF'));
        $this->assertNull(ZteEponSnmpReader::macFromOctets(hex2bin('000000000000')));
    }

    public function test_epon_cards_from_board_table(): void
    {
        $base = '1.3.6.1.4.1.3902.1015.2.1.1.3.1';
        $reader = $this->reader([
            "{$base}.4" => [
                "{$base}.4.1.1.0" => 'PRWG', "{$base}.4.1.1.2" => 'GTGH', "{$base}.4.1.1.8" => 'ETGO',
                "{$base}.4.1.1.17" => 'ETGO', "{$base}.4.1.1.19" => 'HUVQ',
            ],
            "{$base}.5" => ["{$base}.5.1.1.8" => '1', "{$base}.5.1.1.17" => '4'],
        ]);

        $cards = $reader->eponCards(new SnmpOlt);

        $this->assertSame([8, 17], array_column($cards, 'slot'));
        $this->assertSame([1, 4], array_column($cards, 'status_code'));
        $this->assertSame('ETGO', $cards[0]['cfg_type']);
    }

    public function test_unreadable_board_table_returns_null(): void
    {
        $this->assertNull($this->reader([])->eponCards(new SnmpOlt));
    }

    public function test_unknown_port_naming_returns_null(): void
    {
        $reader = $this->reader([
            '1.3.6.1.2.1.31.1.1.1.1' => ['1.3.6.1.2.1.31.1.1.1.1.285280257' => 'EPON 1/8/1'],
        ]);

        $this->assertNull($reader->inventory(new SnmpOlt, [['slot' => 8, 'cfg_type' => 'ETGO']]));
    }

    /**
     * @param  array<string, array<string, string>>  $walks
     */
    private function reader(array $walks): ZteEponSnmpReader
    {
        return new ZteEponSnmpReader(new class($walks) extends OltSnmpClient
        {
            /** @param array<string, array<string, string>> $walks */
            public function __construct(private array $walks) {}

            public function walk(SnmpOlt $olt, string $oid, bool $plain = false): array
            {
                return $this->walks[$oid] ?? throw new RuntimeException("SNMP walk failed for {$oid}");
            }
        });
    }
}
