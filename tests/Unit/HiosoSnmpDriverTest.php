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
     * Baris Rx `na` yang HADIR untuk ONU yang PERTAMA KALI diamati (tak ada acuan online di snapshot
     * lalu) = ONU memang offline → langsung offline. Deteksi ONU mati sungguhan tak tertunda debounce.
     */
    public function test_present_na_rx_row_is_offline_when_no_prior_online_reference(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.1.3" => 'Madun'],
            $mac => ["{$mac}.1.3" => 'd05faf84994e'],
            $rx => ["{$rx}.1.3" => 'na'],
        ]);

        // Tak ada snapshot sebelumnya → tak ada acuan online → baris `na` yang hadir = offline.
        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt())[0];

        $this->assertFalse($onu['online'], 'Baris Rx `na` tanpa acuan online = ONU offline');
        $this->assertSame('Offline', $onu['phase_state']);
        $this->assertNull($onu['rx_power_dbm']);
    }

    /**
     * Regresi anti-flap (OLT-HIOSO-PATI port 3, 1 ONU): satu poll dengan Rx `na` untuk ONU yang tadinya
     * online TIDAK boleh langsung menandainya offline — di link lossy ONU online sesekali melapor `na`
     * satu poll, dan pada port 1-ONU itu membuat seluruh port "flapping" down/up (memicu port_down palsu).
     * Transisi online→offline via `na` di-DEBOUNCE: strike pertama tetap online, Rx dibawa 'snmp_stale'.
     */
    public function test_transient_na_after_online_is_debounced_not_flapped(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.1.3" => 'Madun'],
            $mac => ["{$mac}.1.3" => 'd05faf84994e'],
            $rx => ["{$rx}.1.3" => 'na'], // transien satu poll
        ]);

        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        $olt->last_test_result = [
            'port_onus' => ['1_3' => ['onus' => [[
                'onu_key' => '1.3', 'online' => true, 'rx_power_dbm' => -17.24,
                'rx_power_label' => '-17.24 dBm', 'offline_strikes' => 0,
            ]]]],
        ];

        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt)[0];

        $this->assertTrue($onu['online'], 'Satu poll `na` setelah online = transien → tetap online (debounce)');
        $this->assertSame('Online', $onu['phase_state']);
        $this->assertSame(-17.24, $onu['rx_power_dbm']);
        $this->assertSame('snmp_stale', $onu['rx_power_source']);
        $this->assertSame(1, $onu['offline_strikes'], 'Strike bertambah 1 tapi belum menembus ambang');
    }

    /**
     * Sisi lain debounce: ONU yang benar-benar offline melapor `na` beruntun → setelah strike menembus
     * MAX_OFFLINE_STRIKES ONU ditandai offline. Regresi agar debounce tak menutupi ONU yang benar mati.
     */
    public function test_persistent_na_marks_offline_after_debounce_threshold(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.1.3" => 'Madun'],
            $mac => ["{$mac}.1.3" => 'd05faf84994e'],
            $rx => ["{$rx}.1.3" => 'na'],
        ]);

        // Snapshot lalu: sudah 1 strike `na` (MAX_OFFLINE_STRIKES = 2) → poll ini strike ke-2 → offline.
        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        $olt->last_test_result = [
            'port_onus' => ['1_3' => ['onus' => [[
                'onu_key' => '1.3', 'online' => true, 'rx_power_dbm' => -17.24, 'offline_strikes' => 1,
            ]]]],
        ];

        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt)[0];

        $this->assertFalse($onu['online'], '`na` beruntun menembus ambang debounce → offline');
        $this->assertSame('Offline', $onu['phase_state']);
        $this->assertNull($onu['rx_power_dbm']);
        $this->assertSame(2, $onu['offline_strikes']);
    }

    /**
     * Regresi: walk seluruh tabel MAC/Nama/Rx sering terpotong link WAN pada PON padat sehingga
     * hitungan ONU & kelengkapan nama/Rx berubah-ubah antar poll. Bila ifDescr tersedia, driver harus
     * men-scope walk PER PON (`{base}.{PON}`) & menggabung — walk kecil per PON hampir selalu utuh.
     * Di sini HANYA OID ber-scope (`{base}.1`, `{base}.2`) yang berisi data; walk seluruh-tabel telanjang
     * kosong → ONU hanya muncul kalau driver benar-benar memakai jalur per-PON.
     */
    public function test_per_port_walk_scopes_by_pon_and_merges(): void
    {
        $ifd = '1.3.6.1.2.1.2.2.1.2';
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            // ifDescr → dua PON (Pon-Nni1, Pon-Nni2); walk seluruh tabel ONU sengaja TAK diisi.
            $ifd => ["{$ifd}.1" => 'Pon-Nni1', "{$ifd}.2" => 'Pon-Nni2'],
            "{$mac}.1" => ["{$mac}.1.1" => 'ec237bd78071'],
            "{$mac}.2" => ["{$mac}.2.3" => 'd05fafd2a10d'],
            "{$name}.1" => ["{$name}.1.1" => 'onu-p1'],
            "{$name}.2" => ["{$name}.2.3" => 'onu-p2'],
            "{$rx}.1" => ["{$rx}.1.1" => '-20.36'],
            "{$rx}.2" => ["{$rx}.2.3" => '-22.10'],
        ]);

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt());
        $byKey = collect($onus)->keyBy('onu_key');

        $this->assertCount(2, $onus, 'ONU dari kedua PON harus tergabung dari walk per-PON');

        $this->assertSame('onu-p1', $byKey['1.1']['name']);
        $this->assertSame(-20.36, $byKey['1.1']['rx_power_dbm']);
        $this->assertTrue($byKey['1.1']['online']);

        $this->assertSame([1, 2, 3], [$byKey['2.3']['slot'], $byKey['2.3']['port'], $byKey['2.3']['onu_id']]);
        $this->assertSame('onu-p2', $byKey['2.3']['name']);
        $this->assertSame(-22.10, $byKey['2.3']['rx_power_dbm']);
        $this->assertTrue($byKey['2.3']['online']);
    }

    /**
     * Regresi (gejala utama user): di link terburuk, walk MAC sesekali memangkas ONU sebuah PON
     * (hitungan ONU/port melompat-lompat). Carry-forward: ONU yang dikenal poll lalu tapi ABSEN dari
     * walk MAC cycle ini TETAP dipertahankan (data terakhir, Rx 'snmp_stale') alih-alih hilang — total
     * ONU/PON jadi stabil. `missed_polls` bertambah tiap absen.
     */
    public function test_missing_mac_row_carries_onu_forward_from_previous_roster(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        // Walk MAC hanya mengembalikan 1.1; ONU 1.5 (ada di snapshot lalu) terpotong dari walk.
        $snmp = new FakeHiosoSnmp([
            $mac => ["{$mac}.1.1" => 'ec237bd78071'],
            $name => ["{$name}.1.1" => 'onu-a'],
            $rx => ["{$rx}.1.1" => '-20.36'],
        ]);

        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        $olt->last_test_result = ['port_onus' => ['1_5' => ['onus' => [
            ['onu_key' => '1.1', 'online' => true, 'rx_power_dbm' => -20.0],
            ['onu_key' => '1.5', 'name' => 'Budi', 'mac' => 'D0:5F:AF:D2:A1:0D',
                'online' => true, 'rx_power_dbm' => -18.0, 'rx_power_label' => '-18.00 dBm', 'missed_polls' => 0],
        ]]]];

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt);
        $byKey = collect($onus)->keyBy('onu_key');

        $this->assertCount(2, $onus, 'ONU 1.5 yang terpotong walk MAC harus dipertahankan, bukan hilang');

        // 1.1 terbaca segar.
        $this->assertSame(-20.36, $byKey['1.1']['rx_power_dbm']);
        $this->assertSame('snmp', $byKey['1.1']['rx_power_source']);
        $this->assertSame(0, $byKey['1.1']['missed_polls']);

        // 1.5 carry-forward: data terakhir dipertahankan, Rx 'snmp_stale', missed_polls naik jadi 1.
        $this->assertSame([1, 5], [$byKey['1.5']['port'], $byKey['1.5']['onu_id']]);
        $this->assertSame('Budi', $byKey['1.5']['name']);
        $this->assertSame('D0:5F:AF:D2:A1:0D', $byKey['1.5']['mac']);
        $this->assertTrue($byKey['1.5']['online']);
        $this->assertSame(-18.0, $byKey['1.5']['rx_power_dbm']);
        $this->assertSame('snmp_stale', $byKey['1.5']['rx_power_source']);
        $this->assertSame(1, $byKey['1.5']['missed_polls']);
    }

    /**
     * Sisi lain carry-forward: ONU yang benar-benar di-delete di OLT (MAC-nya hilang permanen) TIDAK
     * boleh menempel selamanya. Setelah absen MAX_MISSED_POLLS (12) poll beruntun, ONU dilepas.
     */
    public function test_onu_dropped_after_max_missed_polls(): void
    {
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';

        // Walk MAC kosong; ONU 1.5 di snapshot lalu sudah absen 12 poll → poll ke-13 melepasnya.
        $snmp = new FakeHiosoSnmp([$mac => []]);

        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        $olt->last_test_result = ['port_onus' => ['1_5' => ['onus' => [
            ['onu_key' => '1.5', 'name' => 'Budi', 'mac' => 'D0:5F:AF:D2:A1:0D',
                'online' => true, 'missed_polls' => 12],
        ]]]];

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt);

        $this->assertSame([], $onus, 'ONU absen > MAX_MISSED_POLLS harus dilepas dari roster');
    }
}
