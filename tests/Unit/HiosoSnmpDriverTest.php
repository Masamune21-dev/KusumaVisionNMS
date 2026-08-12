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

    /** @var list<array{oid: string, type: string, value: string}> */
    public array $sets = [];

    public bool $setResult = true;

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        return $this->gets[$oid] ?? null;
    }

    public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = 10_000_000, int $retries = 3): array
    {
        return $this->walks[$oid] ?? [];
    }

    public function set(SnmpOlt $olt, string $oid, string $type, string $value): bool
    {
        $this->sets[] = compact('oid', 'type', 'value');

        return $this->setResult;
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
     * Regresi utama (OLT-HIOSO-WIDOROKANDANG PON 1 & OLT-HIOSO-PEKALONGAN PON 3, Agu 2026): sebagian
     * ONU tak dilaporkan DDM-nya oleh OLT (Rx `na` permanen) PADAHAL link-nya Up — CLI
     * `show onu info epon 0/{PON} all` menampilkannya `Up` dengan uptime berjalan. Status online WAJIB
     * diambil dari kolom link-state `.39`, bukan disimpulkan dari ada/tidaknya Rx.
     */
    public function test_link_state_up_keeps_onu_online_even_when_rx_is_na(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';
        $link = '1.3.6.1.4.1.25355.3.2.6.3.2.1.39.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.1.3" => 'rumah', "{$name}.1.4" => 'khoirul'],
            $mac => ["{$mac}.1.3" => 'd05fafc56012', "{$mac}.1.4" => 'd05fafd2db41'],
            $rx => ["{$rx}.1.3" => 'na', "{$rx}.1.4" => 'na'],
            $link => ["{$link}.1.3" => '1', "{$link}.1.4" => '2'], // Up / Down
        ]);

        [$up, $down] = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt());

        $this->assertTrue($up['online'], 'Link-state 1 = Up → online walau Rx `na` (DDM tak dilaporkan)');
        $this->assertSame('Online', $up['phase_state']);
        $this->assertNull($up['rx_power_dbm'], 'Rx memang tak tersedia → kolom Rx kosong, bukan angka palsu');

        $this->assertFalse($down['online'], 'Link-state 2 = Down → offline');
        $this->assertSame('Offline', $down['phase_state']);
    }

    /**
     * Kebalikannya: link-state Down untuk ONU yang tadinya online tetap lewat debounce
     * MAX_OFFLINE_STRIKES, supaya satu pembacaan buruk di link lossy tak membuat port berkedip.
     */
    public function test_link_state_down_is_debounced_for_previously_online_onu(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';
        $link = '1.3.6.1.4.1.25355.3.2.6.3.2.1.39.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.1.3" => 'Madun'],
            $mac => ["{$mac}.1.3" => 'd05faf84994e'],
            $rx => ["{$rx}.1.3" => 'na'],
            $link => ["{$link}.1.3" => '2'],
        ]);

        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        $olt->last_test_result = [
            'port_onus' => ['1_3' => ['onus' => [[
                'onu_key' => '1.3', 'online' => true, 'rx_power_dbm' => -17.24, 'offline_strikes' => 0,
            ]]]],
        ];

        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt)[0];

        $this->assertTrue($onu['online'], 'Strike pertama link-state Down masih dalam jendela debounce');
        $this->assertSame(1, $onu['offline_strikes']);
    }

    /**
     * Rx `na` pada ONU yang link-nya Up hanya boleh membawa nilai lama ('snmp_stale') sebentar; setelah
     * MAX_RX_NA_STRIKES kolom Rx dikosongkan supaya tak menampilkan angka redaman beku selamanya.
     */
    public function test_stale_rx_is_dropped_after_repeated_na_while_link_stays_up(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';
        $link = '1.3.6.1.4.1.25355.3.2.6.3.2.1.39.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.1.3" => 'Madun'],
            $mac => ["{$mac}.1.3" => 'd05faf84994e'],
            $rx => ["{$rx}.1.3" => 'na'],
            $link => ["{$link}.1.3" => '1'],
        ]);

        $olt = new SnmpOlt(['snmp_version' => 'v2c']);
        $olt->last_test_result = [
            'port_onus' => ['1_3' => ['onus' => [[
                'onu_key' => '1.3', 'online' => true, 'rx_power_dbm' => -17.24,
                'rx_power_label' => '-17.24 dBm', 'rx_na_strikes' => 1,
            ]]]],
        ];

        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($olt)[0];

        $this->assertTrue($onu['online'], 'Link tetap Up → ONU tetap online');
        $this->assertNull($onu['rx_power_dbm'], 'Rx `na` beruntun → berhenti membawa nilai lama');
        $this->assertNull($onu['rx_power_source']);
        $this->assertSame(2, $onu['rx_na_strikes']);
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

    /**
     * HA7304: ifDescr meng-expose `Pon-Nni{n}` → getPorts menurunkan port PON dari situ.
     */
    public function test_getports_derives_pon_ports_from_pon_nni_ifdescr(): void
    {
        $ifd = '1.3.6.1.2.1.2.2.1.2';
        $snmp = new FakeHiosoSnmp([
            $ifd => ["{$ifd}.1" => 'Pon-Nni1', "{$ifd}.2" => 'Pon-Nni2', "{$ifd}.5" => 'G1'],
        ]);

        $ports = (new HiosoEponSnmpService($snmp))->getPorts($this->olt());

        $this->assertSame([1, 2], array_column($ports, 'port'));
        $this->assertSame(1, $ports[0]['slot']);
    }

    /**
     * HA7302 (mis. HA7302CSM v7.76): TIDAK ada `Pon-Nni` di IF-MIB (ruang LLID datar). getPorts harus
     * fallback ke satu port EPON agregat (slot 1 / port 1) supaya OLT tetap tampil punya port.
     */
    public function test_getports_falls_back_to_single_port_without_pon_nni(): void
    {
        // ifDescr hanya lo/eth0 (gaya HA7302) — tak ada Pon-Nni.
        $ifd = '1.3.6.1.2.1.2.2.1.2';
        $snmp = new FakeHiosoSnmp([
            $ifd => ["{$ifd}.1" => 'lo', "{$ifd}.2" => 'eth0', "{$ifd}.3" => 'eth0.1'],
        ]);

        $ports = (new HiosoEponSnmpService($snmp))->getPorts($this->olt());

        $this->assertCount(1, $ports);
        $this->assertSame(1, $ports[0]['slot']);
        $this->assertSame(1, $ports[0]['port']);
        $this->assertSame('epon 0/1/1', $ports[0]['name']);
    }

    /**
     * HA7302 rename via SNMP SET: menulis OID nama `.25355.…37.1.{oltId}.{onu}` (writable) dengan nama
     * yang dibersihkan (spasi dibiarkan, kontrol dibuang, dipangkas 32).
     */
    public function test_set_onu_name_writes_via_snmp_set(): void
    {
        $snmp = new FakeHiosoSnmp;
        $olt = new SnmpOlt(['snmp_version' => 'v2c']);

        $result = (new HiosoEponSnmpService($snmp))->setOnuName($olt, 1, 42, '  Budi  Santoso ');

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $snmp->sets);
        $this->assertSame('1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1.1.42', $snmp->sets[0]['oid']);
        $this->assertSame('s', $snmp->sets[0]['type']);
        $this->assertSame('Budi Santoso', $snmp->sets[0]['value']);
    }

    /**
     * Bila agen menolak SET (write community salah / OID read-only), setOnuName melaporkan gagal.
     */
    public function test_set_onu_name_reports_failure_when_agent_rejects(): void
    {
        $snmp = new FakeHiosoSnmp;
        $snmp->setResult = false;
        $olt = new SnmpOlt(['snmp_version' => 'v2c']);

        $result = (new HiosoEponSnmpService($snmp))->setOnuName($olt, 1, 5, 'x');

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }
}
