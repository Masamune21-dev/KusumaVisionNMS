<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use PHPUnit\Framework\TestCase;

class C600CardInventoryTest extends TestCase
{
    /**
     * C600/TITAN chassis card inventory lewat SNMP (zxAnCardTable `.1082.10.1.2.4.1`,
     * index {rack}.{shelf}.{slot}). CLI `show card` tak ter-parse di C600, jadi kolom .2 (kode
     * tipe), .4 (model terdeteksi), .5 (oper-status), .7 (jumlah port), .26/.31 (versi) diverifikasi
     * live ke LAS GALERAS dan diterjemahkan jadi baris card bergaya `show card`.
     */
    public function test_card_inventory_decodes_type_status_ports_versions(): void
    {
        $client = new class extends OltSnmpClient
        {
            public function walk(SnmpOlt $olt, string $oid): array
            {
                $base = '1.3.6.1.4.1.3902.1082.10.1.2.4.1';

                // Nilai live (subset), diketik {slot => value}. Index penuh = {col}.1.1.{slot}.
                $data = [
                    $base.'.2' => [3 => '659974', 10 => '656131', 11 => '656131', 17 => '659979', 18 => '663810'],
                    $base.'.4' => [3 => 'GFGL', 10 => 'SFUB', 11 => '', 17 => '', 18 => 'PRVR'],
                    $base.'.5' => [3 => '1', 10 => '1', 11 => '4', 17 => '4', 18 => '1'],
                    $base.'.7' => [3 => '16', 10 => '4', 11 => '4', 17 => '16', 18 => '0'],
                    $base.'.26' => [3 => 'V2.5.0', 10 => 'V1.6.0', 11 => '', 17 => '', 18 => 'V1.0.0'],
                    $base.'.31' => [3 => 'V1.0.9', 10 => 'N/A', 11 => '', 17 => '', 18 => 'N/A'],
                ];

                $rows = [];
                foreach ($data[$oid] ?? [] as $slot => $value) {
                    $rows["{$oid}.1.1.{$slot}"] = $value;
                }

                return $rows;
            }
        };

        $olt = new SnmpOlt(['vendor' => 'ZTE C600', 'name' => 'LAS GALERAS']);
        $cards = $client->cardInventory($olt);

        $this->assertCount(5, $cards);

        // Terurut menurut rack/shelf/slot → slot 3 pertama.
        $bySlot = collect($cards)->keyBy('slot');

        $s3 = $bySlot[3];
        $this->assertSame(1, $s3['rack']);
        $this->assertSame(1, $s3['shelf']);
        $this->assertSame('GFGL', $s3['cfg_type']);   // kode 659974
        $this->assertSame('GFGL', $s3['real_type']);
        $this->assertSame(16, $s3['port_count']);
        $this->assertSame('INSERVICE', $s3['status']); // oper-status 1
        $this->assertSame('V2.5.0', $s3['hard_ver']);
        $this->assertSame('V1.0.9', $s3['soft_ver']);

        // SFUB inService dengan soft_ver "N/A" → null.
        $s10 = $bySlot[10];
        $this->assertSame('SFUB', $s10['cfg_type']);   // kode 656131
        $this->assertSame('INSERVICE', $s10['status']);
        $this->assertSame(4, $s10['port_count']);
        $this->assertNull($s10['soft_ver']);           // "N/A" → null

        // Board offline (hwOffline=4): model terdeteksi kosong → cfg_type dari kode; status OFFLINE.
        $s11 = $bySlot[11];
        $this->assertSame('SFUB', $s11['cfg_type']);   // kode 656131 walau .4 kosong
        $this->assertNull($s11['real_type']);
        $this->assertSame('OFFLINE', $s11['status']);

        $s17 = $bySlot[17];
        $this->assertSame('GFGN', $s17['cfg_type']);   // kode 659979
        $this->assertSame('OFFLINE', $s17['status']);
        $this->assertSame(16, $s17['port_count']);

        // Kartu daya PRVR (kode 663810): 0 port, inService.
        $s18 = $bySlot[18];
        $this->assertSame('PRVR', $s18['cfg_type']);
        $this->assertSame(0, $s18['port_count']);
        $this->assertSame('INSERVICE', $s18['status']);
    }
}
