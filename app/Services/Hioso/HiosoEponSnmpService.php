<?php

namespace App\Services\Hioso;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use Throwable;

/**
 * Driver SNMP read HiOSO / V-Sol EPON (enterprise `25355`, mis. chipset HA7304).
 *
 * Inventory ONU diambil dari tiga OID kanonik yang sudah diverifikasi live (lihat
 * `SMARTOLT_HIOSO_GUIDE.md` §4.3) — name / MAC / Rx — yang di-index oleh **dua segmen terakhir**
 * OID = `{PON}.{ONU}`. HA7304 single-shelf, jadi `slot` selalu 1; `port` = nomor PON.
 *
 * Berdiri sendiri: transport {@see HiosoSnmp} + helper {@see HiosoValue} (bukan milik C-Data).
 * JANGAN walk subtree `25355.3.2.6.2.1.*` (puluhan ribu entry, guide §10 quirk #4).
 */
class HiosoEponSnmpService implements SmartOltSnmpDriver
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';

    /** Signature firmware OLT, mis. `1.0.0.1/HA7304/SN2018-03-00007` (guide §4.1). */
    private const OLT_FIRMWARE = '1.3.6.1.4.1.25355.3.1.8.1.1.2.1';

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    /** Tabel ONU canonical, index `.{PON}.{ONU}` (guide §4.3). */
    private const ONU_NAME = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';

    private const ONU_MAC = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';

    private const ONU_RX = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

    /** MAC slot hantu (ONU tak terdaftar) di tabel nama `.37.1`. */
    private const ZERO_MAC = '00:00:00:00:00:00';

    /** Berapa poll beruntun sebuah ONU boleh absen dari walk sebelum dilepas dari roster (carry-forward). */
    private const MAX_MISSED_POLLS = 12;

    /**
     * Berapa poll beruntun sebuah ONU yang tadinya online harus melapor Rx `na`/`0` sebelum ditandai
     * offline di SNAPSHOT (data smoothing). ONU offline HiOSO memang melapor `na`, tetapi di link lossy
     * ONU yang SEDANG online pun sesekali melapor `na` untuk satu poll — pada PON ber-ONU sedikit (mis.
     * port 1-ONU) satu pembacaan buruk membuat status port (turunan jumlah ONU online) "berkedip" down/up
     * di dashboard/faceplate (gejala OLT-HIOSO-PATI port 3). 2 strike menutup transien 1 sampel tanpa
     * menahan status terlalu lama. Pengiriman ALARM sendiri di-debounce terpisah 2 poll di {@see
     * \App\Services\AlarmEvaluator} (berlaku semua vendor) — jadi ini murni penghalus tampilan, bukan
     * gerbang alarm; sengaja rendah agar tak menumpuk delay dengan debounce alarm.
     */
    private const MAX_OFFLINE_STRIKES = 2;

    public function __construct(private readonly HiosoSnmp $snmp) {}

    public function ping(SnmpOlt $olt): bool
    {
        try {
            $oid = $this->snmp->get($olt, self::SYS_OBJECT_ID);
            if ($oid !== null && str_contains($oid, '25355')) {
                return true;
            }

            // sysObjectID kadang tak terbaca — konfirmasi via tabel ONU EPON.
            return $this->snmp->walk($olt, self::ONU_NAME) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    public function getSystemInfo(SnmpOlt $olt): array
    {
        return [
            'sys_descr' => $this->snmp->get($olt, self::SYS_DESCR),
            'sys_object_id' => $this->snmp->get($olt, self::SYS_OBJECT_ID),
            'sys_uptime' => $this->snmp->get($olt, self::SYS_UPTIME),
            'sys_name' => $this->snmp->get($olt, self::SYS_NAME),
            'firmware' => $this->snmp->get($olt, self::OLT_FIRMWARE),
        ];
    }

    /**
     * Port PON dari ifDescr `Pon-Nni{n}` (guide §4.2). `ifOperStatus` HA7304 TIDAK reliable untuk
     * status PON physical, jadi tidak dipakai — status ditentukan dari jumlah ONU online di scanner.
     */
    public function getPorts(SnmpOlt $olt): array
    {
        $ports = [];

        foreach ($this->snmp->walk($olt, self::IF_DESCR) as $oid => $label) {
            if (! preg_match('/pon-?nni\s*(\d+)/i', (string) $label, $m)) {
                continue;
            }

            $port = (int) $m[1];
            $ports[] = [
                'if_index' => HiosoValue::oidLastSegments($oid, 1)[0] ?? $port,
                'name' => sprintf('epon 0/1/%d', $port),
                'slot' => 1,
                'port' => $port,
                'oper_status_code' => null,
                'oper_status' => 'unknown',
            ];
        }

        usort($ports, fn ($a, $b) => $a['port'] <=> $b['port']);

        return $ports;
    }

    public function getRegisteredOnus(SnmpOlt $olt): array
    {
        // Daftar PON dari ifDescr (walk kecil & stabil). Dipakai untuk men-scope walk tabel ONU per
        // PON — walk seluruh tabel sering terpotong link WAN pada port padat sehingga hitungan ONU &
        // kelengkapan nama/Rx berubah-ubah antar poll (lihat {@see walkTable}).
        $ports = array_map(static fn (array $p): int => (int) $p['port'], $this->getPorts($olt));

        // Roster ONU dari poll SEBELUMNYA — dipakai carry-forward: poll yang terpotong link lossy hanya
        // boleh MENAMBAH/meng-update ONU, tak pernah menghapus ONU yang sudah dikenal. Registrasi EPON
        // stabil (MAC menetap meski ONU mati → ONU offline tetap terbaca 'na'), jadi baris MAC yang
        // benar-benar hilang dari walk = walk tak sampai, BUKAN ONU terhapus. ONU yang hilang
        // MAX_MISSED_POLLS poll beruntun (indikasi benar-benar di-delete) baru dilepas. Ini menstabilkan
        // total ONU/PON di link terburuk, melengkapi walk per-PON.
        $previous = $this->previousOnus($olt);

        // Tabel MAC = sumber kebenaran registrasi. Tabel nama `.37.1` memuat slot HANTU (ONU pernah
        // terdaftar/ter-reserve) ber-MAC `000000000000` yang bukan ONU nyata; hitungan web OLT hanya
        // menghitung slot ber-MAC non-nol.
        $macRows = $this->walkTable($olt, self::ONU_MAC, $ports);
        if ($macRows === [] && $previous === []) {
            return [];
        }

        // Kumpulkan ONU terdaftar (MAC non-nol). Kunci `{PON}.{ONU}`-nya dipakai sebagai TARGET
        // kelengkapan saat walk tabel Nama & Rx: link WAN sering memotong walk di tengah sehingga
        // Rx/status sebagian ONU hilang (tampak offline padahal online, terutama saat polling
        // terjadwal men-scan banyak OLT bersamaan). Dengan target ini {@see robustWalk} mengulang
        // walk sampai semua ONU ter-cover → polling terjadwal jadi selengkap refresh manual.
        $registered = [];
        foreach ($macRows as $oid => $macVal) {
            $segments = HiosoValue::oidLastSegments($oid, 2);
            if ($segments === null) {
                continue;
            }

            $mac = HiosoValue::macFromHex($macVal);
            if ($mac === null || $mac === self::ZERO_MAC) {
                continue; // slot hantu / belum terdaftar
            }

            [$port, $onuId] = $segments;
            $registered["{$port}.{$onuId}"] = [$port, $onuId, $mac];
        }

        // Walk Nama & Rx hanya bila ada ONU terbaca cycle ini; `$onuKeys` = TARGET kelengkapan agar
        // {@see robustWalk} mengulang sampai semua ONU per PON ter-cover (Rx/nama tak bolong).
        $onuKeys = array_keys($registered);
        $nameByKey = $onuKeys === [] ? [] : $this->indexByPonOnu($this->walkTable($olt, self::ONU_NAME, $ports, $onuKeys));
        $rxScan = $onuKeys === [] ? ['valid' => [], 'seen' => []] : $this->rxScan($olt, $ports, $onuKeys);

        $onus = [];

        foreach ($registered as $key => [$port, $onuId, $mac]) {
            $name = HiosoValue::clean($nameByKey[$key] ?? null);
            $prev = $previous[$key] ?? null;

            if (isset($rxScan['seen'][$key]) && ($rxScan['valid'][$key] ?? null) !== null) {
                // Baris Rx terbaca nilai valid → ONU online; reset penghitung strike transien.
                $rx = $rxScan['valid'][$key];
                $online = true;
                $rxLabel = sprintf('%.2f dBm', $rx);
                $rxSource = 'snmp';
                $strikes = 0;
            } elseif (isset($rxScan['seen'][$key])) {
                // Baris Rx HADIR tapi `na`/`0`/di luar jendela. ONU offline HiOSO memang melapor `na`,
                // NAMUN di link lossy ONU yang sedang ONLINE pun sesekali melapor `na` untuk satu poll —
                // pada PON ber-ONU sedikit (mis. port 1-ONU) ini membuat seluruh port "flapping" down/up.
                // Transisi online→offline via `na` di-DEBOUNCE {@see MAX_OFFLINE_STRIKES}: baru offline
                // setelah `na` beruntun. ONU yang sudah offline poll lalu (atau pertama kali diamati,
                // tanpa acuan online) langsung offline — deteksi ONU mati sungguhan tak tertunda.
                $prevOnline = (bool) ($prev['online'] ?? false);
                $strikes = (int) ($prev['offline_strikes'] ?? 0) + 1;

                if ($prevOnline && $strikes < self::MAX_OFFLINE_STRIKES) {
                    // Masih dalam jendela debounce → pertahankan online; Rx dibawa 'snmp_stale' agar
                    // tampil kontinu tapi TAK dicatat ke time-series (lihat PollOltJob).
                    $online = true;
                    $rx = $this->prevRx($prev);
                    $rxLabel = $prev['rx_power_label'] ?? ($rx !== null ? sprintf('%.2f dBm', $rx) : null);
                    $rxSource = $rx !== null ? 'snmp_stale' : null;
                } else {
                    $online = false;
                    $rx = null;
                    $rxLabel = null;
                    $rxSource = null;
                }
            } else {
                // Baris Rx ABSEN dari walk = walk terpotong link lossy, BUKAN bukti ONU offline (ONU
                // offline HiOSO tetap melapor `na` → barisnya tetap ada). Pertahankan status terakhir
                // dari snapshot poll sebelumnya; Rx dibawa 'snmp_stale'. Absen bukan pembacaan buruk
                // (sekadar tak ada data) → strike dibawa apa adanya, tak bertambah.
                $online = (bool) ($prev['online'] ?? true); // tak ada acuan → MAC terdaftar, asumsikan up
                $rx = $this->prevRx($prev);
                $rxLabel = $prev['rx_power_label'] ?? ($rx !== null ? sprintf('%.2f dBm', $rx) : null);
                $rxSource = $rx !== null ? 'snmp_stale' : null;
                $strikes = (int) ($prev['offline_strikes'] ?? 0);
            }

            // Nama kadang absen dari walk (truncation) walau ONU terbaca di MAC → pertahankan nama lama.
            $name ??= HiosoValue::clean($previous[$key]['name'] ?? null);

            $onus[$key] = $this->buildOnu($port, $onuId, $mac, $name, $online, $rx, $rxLabel, $rxSource, 0, $strikes);
        }

        // Carry-forward roster: ONU yang dikenal poll lalu tapi ABSEN dari walk MAC cycle ini (link
        // lossy memangkasnya) dipertahankan pakai data terakhir, Rx ditandai 'snmp_stale'. Dilepas
        // hanya setelah hilang MAX_MISSED_POLLS poll beruntun (indikasi benar-benar di-delete di OLT).
        foreach ($previous as $key => $prev) {
            if (isset($onus[$key])) {
                continue; // sudah terbaca segar cycle ini
            }

            $segments = HiosoValue::oidLastSegments((string) $key, 2);
            if ($segments === null) {
                continue;
            }

            $missed = (int) ($prev['missed_polls'] ?? 0) + 1;
            if ($missed > self::MAX_MISSED_POLLS) {
                continue; // dianggap benar-benar dihapus dari OLT → lepas dari roster
            }

            [$port, $onuId] = $segments;
            $rx = $this->prevRx($prev);
            $onus[$key] = $this->buildOnu(
                $port,
                $onuId,
                $prev['mac'] ?? ($prev['serial_number'] ?? null),
                HiosoValue::clean($prev['name'] ?? null),
                (bool) ($prev['online'] ?? true),
                $rx,
                $prev['rx_power_label'] ?? ($rx !== null ? sprintf('%.2f dBm', $rx) : null),
                $rx !== null ? 'snmp_stale' : null,
                $missed,
                (int) ($prev['offline_strikes'] ?? 0),
            );
        }

        $onus = array_values($onus);
        usort($onus, fn ($a, $b) => [$a['slot'], $a['port'], $a['onu_id']] <=> [$b['slot'], $b['port'], $b['onu_id']]);

        return $onus;
    }

    /**
     * Rakit satu record ONU bentuk-ZTE (dipakai baik ONU terbaca segar maupun carry-forward).
     *
     * @return array<string, mixed>
     */
    private function buildOnu(int $port, int $onuId, ?string $mac, ?string $name, bool $online, ?float $rx, ?string $rxLabel, ?string $rxSource, int $missedPolls, int $offlineStrikes = 0): array
    {
        return [
            'onu_key' => "{$port}.{$onuId}",
            'if_index' => null,
            'slot' => 1,
            'port' => $port,
            'onu_id' => $onuId,
            'interface' => sprintf('epon 0/1/%d:%d', $port, $onuId),
            'type_name' => null,
            'name' => $name,
            'description' => null,
            // EPON tak punya serial tradisional — MAC adalah identifier ONU (guide §7.8).
            'serial_number' => $mac,
            'mac' => $mac,
            'vendor_id' => null,
            'admin_state' => 'unknown',
            'phase_state' => $online ? 'Online' : 'Offline',
            'online' => $online,
            'last_down_cause' => null,
            'rx_power_dbm' => $rx,
            'rx_power_label' => $rxLabel,
            'rx_power_source' => $rxSource,
            'missed_polls' => $missedPolls,
            // Penghitung debounce anti-flap: berapa poll `na` beruntun sejak Rx valid terakhir.
            'offline_strikes' => $offlineStrikes,
        ];
    }

    /**
     * Rx numerik dari record ONU poll sebelumnya (untuk carry-forward), atau null.
     *
     * @param  array<string, mixed>|null  $prev
     */
    private function prevRx(?array $prev): ?float
    {
        $rx = $prev['rx_power_dbm'] ?? null;

        return is_numeric($rx) ? (float) $rx : null;
    }

    public function getRegisteredOnusByPort(SnmpOlt $olt, int $slot, int $port): array
    {
        return array_values(array_filter(
            $this->getRegisteredOnus($olt),
            fn (array $onu) => $onu['slot'] === $slot && $onu['port'] === $port,
        ));
    }

    public function getPortRxMap(SnmpOlt $olt): array
    {
        $ports = array_map(static fn (array $p): int => (int) $p['port'], $this->getPorts($olt));

        return $this->rxScan($olt, $ports)['valid'];
    }

    public function countRegisteredOnus(SnmpOlt $olt): int
    {
        try {
            // Hanya slot dgn MAC non-nol = ONU terdaftar sungguhan (tabel nama memuat slot hantu).
            $ports = array_map(static fn (array $p): int => (int) $p['port'], $this->getPorts($olt));
            $count = 0;
            foreach ($this->walkTable($olt, self::ONU_MAC, $ports) as $value) {
                $mac = HiosoValue::macFromHex($value);
                if ($mac !== null && $mac !== self::ZERO_MAC) {
                    $count++;
                }
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    public function getUnconfiguredOnus(SnmpOlt $olt): array
    {
        // Autofind/unconfigured HA7304 belum dipetakan — fitur kandidat (guide §13).
        return [];
    }

    /**
     * Walk sebuah tabel kanonik ONU **per PON** (`{base}.{PON}`) lalu gabung, alih-alih satu walk
     * seluruh tabel. Walk penuh tabel besar (port padat, mis. 27 ONU) sering terpotong link WAN →
     * hitungan ONU/kelengkapan nama-Rx berubah-ubah antar poll; walk yang di-scope per PON jauh lebih
     * kecil sehingga hampir selalu utuh (terverifikasi live: full walk truncate, per-PON stabil).
     * Untuk sisa truncation langka, `$targetKeys` (`{PON}.{ONU}` dari tabel MAC) memaksa {@see
     * robustWalk} mengulang sampai PON itu ter-cover.
     *
     * PON yang walk-nya gagal total (timeout) di-skip — port lain tetap ter-scan; PON itu pulih di
     * poll berikutnya (untuk Rx, status terakhir dipertahankan {@see previousOnuState}). Bila daftar
     * port kosong (ifDescr gagal) → fallback walk seluruh tabel (perilaku lama).
     *
     * @param  array<int, int>  $ports  nomor PON
     * @param  array<int, string>  $targetKeys  `{PON}.{ONU}` (dikelompokkan per PON di sini)
     * @return array<string, string>
     */
    private function walkTable(SnmpOlt $olt, string $baseOid, array $ports, array $targetKeys = []): array
    {
        if ($ports === []) {
            return $this->robustWalk($olt, $baseOid, $targetKeys);
        }

        $targetsByPort = [];
        foreach ($targetKeys as $key) {
            [$port] = explode('.', $key);
            $targetsByPort[(int) $port][] = $key;
        }

        $merged = [];
        foreach ($ports as $port) {
            try {
                $merged += $this->robustWalk($olt, "{$baseOid}.{$port}", $targetsByPort[$port] ?? []);
            } catch (Throwable) {
                continue; // PON ini gagal total → biar port lain tetap ter-scan
            }
        }

        return $merged;
    }

    /**
     * Walk tahan-lossy: link WAN ke HiOSO kadang memutus GETBULK di tengah → hasil partial
     * (baris ONU berubah-ubah antar walk). Karena registrasi ONU stabil antar-walk (detik), kita
     * walk beberapa kali lalu **gabung by-OID**. Berhenti saat:
     *   1. semua `$targetKeys` (`{PON}.{ONU}` dari tabel MAC) sudah ter-cover — jalur cepat saat
     *      link sehat, umumnya 1 walk; ATAU
     *   2. DUA attempt beruntun tak menambah baris baru — satu attempt tak cukup karena walk yang
     *      terpotong bisa kebetulan mengembalikan prefix pendek yang sama; ATAU
     *   3. `$maxAttempts` tercapai.
     * Kegagalan walk lanjutan ditoleransi selama sudah ada baris terkumpul.
     *
     * @param  array<int, string>  $targetKeys  kunci `{PON}.{ONU}` yang diharapkan ada (kosong = tak ada target)
     * @return array<string, string>
     */
    private function robustWalk(SnmpOlt $olt, string $oid, array $targetKeys = [], int $maxAttempts = 5): array
    {
        $merged = [];
        $stableStreak = 0;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $rows = $this->snmp->walk($olt, $oid);
            } catch (Throwable $e) {
                if ($merged === []) {
                    throw $e; // walk pertama gagal total → biarkan scan menandai error
                }
                break;
            }

            $before = count($merged);
            $merged += $rows; // union; pertahankan nilai baris yang lebih dulu terlihat

            if ($this->coversKeys($merged, $targetKeys)) {
                break; // semua ONU target ter-cover → lengkap
            }

            // Subtree kosong (PON tanpa ONU) → walk pertama balik [] (agen menjawab, bukan timeout yg
            // melempar) & tak ada target untuk dikejar → berhenti; hindari 5× walk sia-sia per PON kosong.
            if ($rows === [] && $merged === [] && $targetKeys === []) {
                break;
            }

            if ($before > 0 && count($merged) === $before) {
                if (++$stableStreak >= 2) {
                    break; // dua attempt beruntun tanpa baris baru → dianggap selengkap yang bisa didapat
                }
            } else {
                $stableStreak = 0;
            }
        }

        return $merged;
    }

    /**
     * Apakah baris walk (di-key OID) sudah memuat SEMUA `$targetKeys` (`{PON}.{ONU}`)?
     * Target kosong → selalu false (tak ada acuan; robustWalk jatuh ke deteksi stabil).
     *
     * @param  array<string, string>  $rows
     * @param  array<int, string>  $targetKeys
     */
    private function coversKeys(array $rows, array $targetKeys): bool
    {
        if ($targetKeys === []) {
            return false;
        }

        $seen = [];
        foreach ($rows as $oid => $value) {
            $segments = HiosoValue::oidLastSegments($oid, 2);
            if ($segments !== null) {
                $seen["{$segments[0]}.{$segments[1]}"] = true;
            }
        }

        foreach ($targetKeys as $key) {
            if (! isset($seen[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Walk tabel Rx sekali (robust), lalu pisahkan dua hal yang WAJIB dibedakan:
     *   - `valid`: `{PON}.{ONU}` => dBm untuk baris yang terbaca sebagai nilai valid (ONU online).
     *   - `seen` : `{PON}.{ONU}` => true untuk SETIAP baris Rx yang MUNCUL di walk, apa pun nilainya
     *              (termasuk `na`/`0`). ONU offline HiOSO TETAP melapor `na` → barisnya tetap ada; jadi
     *              baris yang muncul = pembacaan sungguhan, sedangkan baris yang SAMA SEKALI absen =
     *              walk terpotong link lossy (bukan bukti ONU mati). Pemisahan ini yang mencegah port
     *              ber-ONU sedikit "flapping" saat walk sesekali tak sampai (lihat getRegisteredOnus).
     *
     * @param  array<int, int>  $ports  nomor PON untuk men-scope walk per PON (lihat walkTable)
     * @param  array<int, string>  $onuKeys  target kelengkapan `{PON}.{ONU}` (lihat getRegisteredOnus)
     * @return array{valid: array<string, float>, seen: array<string, bool>}
     */
    private function rxScan(SnmpOlt $olt, array $ports = [], array $onuKeys = []): array
    {
        $valid = [];
        $seen = [];

        foreach ($this->walkTable($olt, self::ONU_RX, $ports, $onuKeys) as $oid => $value) {
            $segments = HiosoValue::oidLastSegments($oid, 2);
            if ($segments === null) {
                continue;
            }

            $key = "{$segments[0]}.{$segments[1]}";
            $seen[$key] = true;

            $dbm = HiosoValue::rxDbm($value);
            if ($dbm !== null) {
                $valid[$key] = $dbm;
            }
        }

        return ['valid' => $valid, 'seen' => $seen];
    }

    /**
     * Roster ONU dari snapshot poll SEBELUMNYA (`last_test_result.port_onus`), di-key `{PON}.{ONU}` →
     * record ONU mentah. Dipakai untuk (a) mempertahankan status/nama saat baris sebuah ONU absen dari
     * walk (truncation), dan (b) carry-forward roster: ONU yang absen total dari walk MAC tetap
     * dipertahankan alih-alih hilang (lihat getRegisteredOnus). Aman: saat getRegisteredOnus dipanggil,
     * scanner belum menimpa `last_test_result` (masih berisi hasil poll sebelumnya).
     *
     * @return array<string, array<string, mixed>>
     */
    private function previousOnus(SnmpOlt $olt): array
    {
        $state = [];

        foreach ((array) data_get($olt->last_test_result, 'port_onus', []) as $port) {
            foreach ((array) ($port['onus'] ?? []) as $onu) {
                $key = $onu['onu_key'] ?? null;
                if (is_string($key)) {
                    $state[$key] = $onu;
                }
            }
        }

        return $state;
    }

    /**
     * Re-key hasil walk (OID => value) menjadi `{PON}.{ONU}` => value dari dua segmen terakhir OID.
     *
     * @param  array<string, string>  $rows
     * @return array<string, string>
     */
    private function indexByPonOnu(array $rows): array
    {
        $map = [];

        foreach ($rows as $oid => $value) {
            $segments = HiosoValue::oidLastSegments($oid, 2);
            if ($segments !== null) {
                $map["{$segments[0]}.{$segments[1]}"] = $value;
            }
        }

        return $map;
    }
}
