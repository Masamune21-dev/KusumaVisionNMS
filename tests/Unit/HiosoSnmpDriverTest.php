<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Hioso\HiosoEponSnmpService;
use App\Services\Hioso\HiosoSnmp;
use Tests\TestCase;

/**
 * Stub SNMP HiOSO: kembalikan walk sintetis di-key oleh base-OID yang diminta driver.
 */
class FakeHiosoSnmp extends HiosoSnmp
{
    /**
     * @param  array<string, array<string, string>>  $walks
     * @param  array<string, ?string>  $gets
     */
    public function __construct(private array $walks = [], private array $gets = []) {}

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        return $this->gets[$oid] ?? null;
    }

    public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = 10_000_000, int $retries = 3): array
    {
        return $this->walks[$oid] ?? [];
    }
}

/**
 * Stub SNMP HiOSO lossy: tiap OID punya ANTREAN hasil walk. Walk pertama bisa terpotong (partial),
 * walk berikutnya lebih lengkap; hasil terakhir "menempel" (walk stabil setelahnya). Meniru link WAN
 * yang memutus GETBULK di tengah.
 */
class QueuedHiosoSnmp extends HiosoSnmp
{
    /**
     * @param  array<string, array<int, array<string, string>>>  $queues
     */
    public function __construct(private array $queues = []) {}

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        return null;
    }

    public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = 10_000_000, int $retries = 3): array
    {
        if (! isset($this->queues[$oid]) || $this->queues[$oid] === []) {
            return [];
        }

        return count($this->queues[$oid]) > 1
            ? array_shift($this->queues[$oid])
            : $this->queues[$oid][0];
    }
}

class HiosoSnmpDriverTest extends TestCase
{
    private function olt(): SnmpOlt
    {
        return new SnmpOlt(['snmp_version' => 'v2c']);
    }

    public function test_hioso_driver_parses_inventory_rx_and_offline(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => [
                // index = {PON}.{ONU}
                "{$name}.1.1" => 'serlybendokaton',
                "{$name}.1.7" => 'offline-onu',
            ],
            $mac => [
                "{$mac}.1.1" => 'ec237bd78071',
                "{$mac}.1.7" => 'd05fafd2a10d',
            ],
            $rx => [
                "{$rx}.1.1" => '-20.36',
                "{$rx}.1.7" => 'na', // offline / no signal
            ],
        ]);

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt());

        $this->assertCount(2, $onus);

        [$a, $b] = $onus;
        // Online ONU dengan Rx valid
        $this->assertSame([1, 1, 1], [$a['slot'], $a['port'], $a['onu_id']]);
        $this->assertSame('epon 0/1/1:1', $a['interface']);
        $this->assertSame('serlybendokaton', $a['name']);
        $this->assertSame('EC:23:7B:D7:80:71', $a['mac']);
        $this->assertSame('EC:23:7B:D7:80:71', $a['serial_number']); // EPON: identitas = MAC
        $this->assertTrue($a['online']);
        $this->assertSame('Online', $a['phase_state']);
        $this->assertSame(-20.36, $a['rx_power_dbm']);
        $this->assertSame('snmp', $a['rx_power_source']);

        // Offline ONU (Rx "na") — tetap punya MAC, tapi tak online & Rx null
        $this->assertSame([1, 1, 7], [$b['slot'], $b['port'], $b['onu_id']]);
        $this->assertSame('D0:5F:AF:D2:A1:0D', $b['mac']);
        $this->assertFalse($b['online']);
        $this->assertSame('Offline', $b['phase_state']);
        $this->assertNull($b['rx_power_dbm']);
    }

    public function test_hioso_driver_ignores_zero_and_out_of_range_rx(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.2.3" => 'cust'],
            $mac => ["{$mac}.2.3" => 'ec237bd78071'],
            $rx => ["{$rx}.2.3" => '0'], // 0 = no signal
        ]);

        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt())[0];

        $this->assertSame([1, 2, 3], [$onu['slot'], $onu['port'], $onu['onu_id']]);
        $this->assertNull($onu['rx_power_dbm']);
        $this->assertFalse($onu['online']);
    }

    /**
     * Regresi: saat polling terjadwal, walk RX/Nama sering terpotong sehingga sebagian ONU tampak
     * offline & tanpa Rx. robustWalk harus mengulang walk (target = kunci ONU dari tabel MAC yang
     * andal) sampai semua ONU ter-cover — sehingga hasil sama lengkapnya dengan refresh manual.
     */
    public function test_robust_walk_recovers_rx_and_status_from_partial_walks(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new QueuedHiosoSnmp([
            // MAC stabil → semua ONU selalu terload (sesuai gejala: ONU lengkap, Rx/status yang bolong).
            $mac => [[
                "{$mac}.1.1" => 'ec237bd78071',
                "{$mac}.1.7" => 'd05fafd2a10d',
            ]],
            // Nama: walk pertama terpotong (hanya 1.1), walk kedua lengkap.
            $name => [
                ["{$name}.1.1" => 'onu-a'],
                ["{$name}.1.1" => 'onu-a', "{$name}.1.7" => 'onu-b'],
            ],
            // Rx: walk pertama terpotong (hanya 1.1) → tanpa robustWalk, ONU 1.7 salah tampak offline.
            $rx => [
                ["{$rx}.1.1" => '-20.36'],
                ["{$rx}.1.1" => '-20.36', "{$rx}.1.7" => '-24.10'],
            ],
        ]);

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt());

        $this->assertCount(2, $onus);
        [$a, $b] = $onus;

        $this->assertSame([1, 1], [$a['port'], $a['onu_id']]);
        $this->assertTrue($a['online']);
        $this->assertSame(-20.36, $a['rx_power_dbm']);

        // ONU 1.7: walk Rx pertama tak memuatnya → harus dipulihkan walk kedua, bukan tercatat offline.
        $this->assertSame([1, 7], [$b['port'], $b['onu_id']]);
        $this->assertTrue($b['online'], 'ONU 1.7 harus online setelah robustWalk memulihkan Rx yang terpotong');
        $this->assertSame(-24.10, $b['rx_power_dbm']);
        $this->assertSame('onu-b', $b['name']);
    }

    /**
     * Regresi anti-flapping (OLT-HIOSO-PATI port 3, 1 ONU): saat walk Rx SAMA SEKALI tak menyertakan
     * baris sebuah ONU (link lossy memotong walk, bahkan setelah robustWalk mengulang), ONU itu TIDAK
     * boleh ditandai offline — ONU offline HiOSO tetap melapor `na` (barisnya ada), jadi baris yang
     * benar-benar hilang = walk tak sampai, bukan bukti mati. Status terakhir dari snapshot poll
     * sebelumnya dipertahankan supaya port 1-ONU tak "down/up" tiap walk yang terpotong.
     */
    public function test_absent_rx_row_keeps_last_known_state_instead_of_flapping_offline(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        // Rx: SELALU partial (hanya 1.1); baris 1.3 tak pernah muncul → robustWalk kehabisan attempt.
        $snmp = new QueuedHiosoSnmp([
            $mac => [[
                "{$mac}.1.1" => 'ec237bd78071',
                "{$mac}.1.3" => 'd05faf84994e',
            ]],
            $name => [[
                "{$name}.1.1" => 'onu-a',
                "{$name}.1.3" => 'Madun',
            ]],
            $rx => [["{$rx}.1.1" => '-20.36']],
        ]);

        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        // Snapshot poll sebelumnya: ONU 1.3 online, Rx -19.00 dBm.
        $olt->last_test_result = [
            'port_onus' => [
                '1_3' => ['onus' => [[
                    'onu_key' => '1.3',
                    'online' => true,
                    'rx_power_dbm' => -19.0,
                    'rx_power_label' => '-19.00 dBm',
                ]]],
            ],
        ];

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt);
        $byKey = collect($onus)->keyBy('onu_key');

        // ONU 1.1 punya baris Rx sungguhan → online normal.
        $this->assertTrue($byKey['1.1']['online']);
        $this->assertSame(-20.36, $byKey['1.1']['rx_power_dbm']);
        $this->assertSame('snmp', $byKey['1.1']['rx_power_source']);

        // ONU 1.3: baris Rx absen → pertahankan status terakhir (online), TAK jadi offline.
        $onu = $byKey['1.3'];
        $this->assertTrue($onu['online'], 'ONU tanpa baris Rx (walk terpotong) tak boleh dianggap offline');
        $this->assertSame('Online', $onu['phase_state']);
        $this->assertSame(-19.0, $onu['rx_power_dbm']);
        // Rx carry-forward ditandai 'snmp_stale' → tak ikut dicatat ke time-series oleh PollOltJob.
        $this->assertSame('snmp_stale', $onu['rx_power_source']);
    }

    /**
     * Baris Rx `na` (ONU sungguh-sungguh offline, barisnya ADA) tetap harus terbaca offline — pembeda
     * dari kasus baris absen di atas. Regresi agar fix anti-flap tak menutupi ONU yang benar mati.
     */
    public function test_present_na_rx_row_is_still_offline(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.1.3" => 'Madun'],
            $mac => ["{$mac}.1.3" => 'd05faf84994e'],
            $rx => ["{$rx}.1.3" => 'na'],
        ]);

        // Meski snapshot sebelumnya online, baris `na` yang HADIR menang → offline.
        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        $olt->last_test_result = [
            'port_onus' => ['1_3' => ['onus' => [[
                'onu_key' => '1.3', 'online' => true, 'rx_power_dbm' => -19.0,
            ]]]],
        ];

        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt)[0];

        $this->assertFalse($onu['online'], 'Baris Rx `na` yang hadir = ONU memang offline');
        $this->assertSame('Offline', $onu['phase_state']);
        $this->assertNull($onu['rx_power_dbm']);
    }
}
