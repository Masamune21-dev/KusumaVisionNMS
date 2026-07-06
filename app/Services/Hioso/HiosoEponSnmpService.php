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
        // Tabel MAC = sumber kebenaran registrasi. Tabel nama `.37.1` memuat slot HANTU (ONU pernah
        // terdaftar/ter-reserve) ber-MAC `000000000000` yang bukan ONU nyata; hitungan web OLT hanya
        // menghitung slot ber-MAC non-nol.
        $macRows = $this->robustWalk($olt, self::ONU_MAC);
        if ($macRows === []) {
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

        if ($registered === []) {
            return [];
        }

        $onuKeys = array_keys($registered);
        $nameByKey = $this->indexByPonOnu($this->robustWalk($olt, self::ONU_NAME, $onuKeys));
        $rxScan = $this->rxScan($olt, $onuKeys);
        $previous = $this->previousOnuState($olt);

        $onus = [];

        foreach ($registered as $key => [$port, $onuId, $mac]) {
            $name = HiosoValue::clean($nameByKey[$key] ?? null);

            if (isset($rxScan['seen'][$key])) {
                // Baris Rx sungguh-sungguh terbaca cycle ini → pembacaan sah: dBm valid = online;
                // `na`/`0`/di luar jendela = ONU memang offline (guide §4.4: ONU offline melapor `na`).
                $rx = $rxScan['valid'][$key] ?? null;
                $online = $rx !== null;
                $rxLabel = $rx !== null ? sprintf('%.2f dBm', $rx) : null;
                $rxSource = $rx !== null ? 'snmp' : null;
            } else {
                // Baris Rx ABSEN dari walk = walk terpotong link lossy, BUKAN bukti ONU offline (ONU
                // offline HiOSO tetap melapor `na` → barisnya tetap ada). Menandainya offline di sini
                // membuat port ber-ONU sedikit (mis. port 1-ONU) "flapping" down/up tiap walk yang tak
                // sampai. Pertahankan status terakhir dari snapshot poll sebelumnya; Rx dibawa sebagai
                // 'snmp_stale' agar tampil kontinu tapi TAK dicatat ke time-series (lihat PollOltJob).
                $prev = $previous[$key] ?? null;
                $online = $prev['online'] ?? true; // tak ada acuan → MAC terdaftar, asumsikan up
                $rx = $prev['rx'] ?? null;
                $rxLabel = $prev['rx_label'] ?? ($rx !== null ? sprintf('%.2f dBm', $rx) : null);
                $rxSource = $rx !== null ? 'snmp_stale' : null;
            }

            $onus[] = [
                'onu_key' => $key,
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
            ];
        }

        usort($onus, fn ($a, $b) => [$a['slot'], $a['port'], $a['onu_id']] <=> [$b['slot'], $b['port'], $b['onu_id']]);

        return $onus;
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
        return $this->rxScan($olt)['valid'];
    }

    public function countRegisteredOnus(SnmpOlt $olt): int
    {
        try {
            // Hanya slot dgn MAC non-nol = ONU terdaftar sungguhan (tabel nama memuat slot hantu).
            $count = 0;
            foreach ($this->robustWalk($olt, self::ONU_MAC) as $value) {
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
     * @param  array<int, string>  $onuKeys  target kelengkapan `{PON}.{ONU}` (lihat getRegisteredOnus)
     * @return array{valid: array<string, float>, seen: array<string, bool>}
     */
    private function rxScan(SnmpOlt $olt, array $onuKeys = []): array
    {
        $valid = [];
        $seen = [];

        foreach ($this->robustWalk($olt, self::ONU_RX, $onuKeys) as $oid => $value) {
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
     * Status ONU dari snapshot poll SEBELUMNYA (`last_test_result.port_onus`), di-key `{PON}.{ONU}`.
     * Dipakai sebagai fallback saat walk Rx tak menyertakan baris sebuah ONU (truncation) → status
     * terakhir dipertahankan alih-alih mengarang "offline". Aman: saat getRegisteredOnus dipanggil,
     * scanner belum menimpa `last_test_result` (masih berisi hasil poll sebelumnya).
     *
     * @return array<string, array{online: bool, rx: float|null, rx_label: string|null}>
     */
    private function previousOnuState(SnmpOlt $olt): array
    {
        $state = [];

        foreach ((array) data_get($olt->last_test_result, 'port_onus', []) as $port) {
            foreach ((array) ($port['onus'] ?? []) as $onu) {
                $key = $onu['onu_key'] ?? null;
                if (! is_string($key)) {
                    continue;
                }

                $rx = $onu['rx_power_dbm'] ?? null;
                $state[$key] = [
                    'online' => (bool) ($onu['online'] ?? false),
                    'rx' => is_numeric($rx) ? (float) $rx : null,
                    'rx_label' => $onu['rx_power_label'] ?? null,
                ];
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
