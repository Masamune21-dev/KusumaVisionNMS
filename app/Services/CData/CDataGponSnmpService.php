<?php

namespace App\Services\CData;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use Throwable;

/**
 * Driver SNMP read C-Data GPON (FD16xxS — 1, 2, 8 PON dst.).
 *
 * Sumber tabel ONU, urut prioritas (terverifikasi live FD1608S-B1 V3.3.86 dan FD1601S-B1 V3.2.5,
 * 28 Sep 2026 — lihat docs/handbook/17-cdata-gpon-snmp-walk.md):
 *  1. NSCRTV-FTTX-GPON-MIB `17409.2.8.4.*` — jalur utama, di-key onuIndex global `0x480000 + seq`.
 *     `gponOnuInfoEntry`: .2 nama (`gpon F/S/P onu N <label>`), .3 serial 8 byte, .5 vendor, .6 model,
 *     .7 onuOperationStatus (1 up / 2 down — cocok 1:1 dgn `show ont info all`), .103 sebab down
 *     terakhir (ekstensi vendor). Rx = `.4.4.1.4.<onuIdx>.<card>.<port>` centi-dBm. MAC opsional
 *     dari `17409.2.3.4.7.1.3.<onuIdx>.1` (ada di FD1608S, tidak di FD1601S).
 *  2. Tabel optik/status `34592.1.5.1.1.2.21.1.1` — hanya cadangan bila kolom NSCRTV kosong. TIDAK
 *     andal: di FD1608S tiga ONU online dilaporkan -1, di FD1601S semua -1 dan kolom penghubungnya
 *     berisi nomor ONU, bukan onuIndex.
 *  3. Legacy/V2 FD-ONU-MIB `34592.1.3.4.1.1.<col>` (index `slot.port.onuId`) — belum pernah ditemui live.
 * Tak ada satu pun tabel tapi kredensial telnet ada → CLI `show ont info all` penuh.
 *
 * {@see self::isV3()} (probe `34592…18.12`) hanya menandai firmware yang CLI Rx & Remote ONT-nya
 * terverifikasi (FD1608S). FD1601S ber-firmware V3.2.5 tapi tak punya tabel itu, jadi inventory
 * sengaja tidak bergantung pada penanda ini.
 */
class CDataGponSnmpService implements SmartOltSnmpDriver
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    private const FD_ONLINE = '1.3.6.1.4.1.34592.1.3.4.1.1.11';

    private const FD_USERINFO = '1.3.6.1.4.1.34592.1.3.4.1.1.4';

    private const OLT_DESC = '1.3.6.1.4.1.34592.1.5.1.1.2.18.2.1.5';

    private const V3_STATUS = '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1';

    // NSCRTV-FTTX-GPON-MIB gponOnuInfoEntry, di-key onuIndex global `0x480000 + seq`.
    private const GPON_NAME = '1.3.6.1.4.1.17409.2.8.4.1.1.2';   // "gpon F/S/P onu N <label>"

    private const GPON_SERIAL = '1.3.6.1.4.1.17409.2.8.4.1.1.3'; // OCTET STRING 8 byte

    private const GPON_VENDOR = '1.3.6.1.4.1.17409.2.8.4.1.1.5'; // "ZTEG", "CDTC", …

    private const GPON_MODEL = '1.3.6.1.4.1.17409.2.8.4.1.1.6';  // "F609V5.3", …

    private const GPON_OPER = '1.3.6.1.4.1.17409.2.8.4.1.1.7';   // 1 = up, 2 = down

    private const GPON_DOWN_CAUSE = '1.3.6.1.4.1.17409.2.8.4.1.1.103'; // "LOS", "dying-gasp", "--"

    private const GPON_RX = '1.3.6.1.4.1.17409.2.8.4.4.1.4';     // centi-dBm, suffix `<idx>.<card>.<port>`

    private const GPON_MAC = '1.3.6.1.4.1.17409.2.3.4.7.1.3';    // Hex-STRING, suffix `<idx>.1`

    // Tabel optik/status 34592 .21.1.1.<col>, index `.1.0.<port>.<onuSeq>.1` sama antar-kolom (cadangan).
    private const V3_OPT_STATUS = '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2';   // 1 = online, -1 = offline

    private const V3_OPT_ONUIDX = '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3';   // onuIndex penghubung ke 17409

    private const V3_OPT_RX = '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.5';       // Rx dBm string (sering `--`)

    // Tabel statistik per-ONU `.18.26.1` — nilainya `-1`, tapi meng-enumerasi seluruh ONU (1 baris/ONU).
    private const V3_ONU_ENUM = '1.3.6.1.4.1.34592.1.5.1.1.2.18.26.1.2';

    public function __construct(
        private readonly CDataSnmp $snmp,
        private readonly CDataGponCliService $cli,
    ) {}

    public function ping(SnmpOlt $olt): bool
    {
        try {
            $oid = $this->snmp->get($olt, self::SYS_OBJECT_ID);
            if ($oid !== null && str_contains($oid, '34592')) {
                return true;
            }

            // C-Data GPON umumnya melapor sysObjectID 17409 (sama dgn EPON) — konfirmasi lewat tabel ONU.
            return $this->optionalWalk($olt, self::GPON_NAME) !== []
                || $this->isV3($olt)
                || $this->optionalWalk($olt, self::FD_ONLINE) !== [];
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
            'firmware_v3' => $this->isV3($olt),
        ];
    }

    public function getPorts(SnmpOlt $olt): array
    {
        $descrs = $this->snmp->walk($olt, self::IF_DESCR);
        $statuses = $this->snmp->walk($olt, self::IF_OPER_STATUS);
        $ports = [];

        foreach ($descrs as $oid => $label) {
            if (! preg_match('/gpon\s+\d+\/(\d+)\/(\d+)/i', $label, $m)) {
                continue;
            }

            $ifIndex = (CDataValue::oidLastSegments($oid, 1) ?? [null])[0];
            if ($ifIndex === null) {
                continue;
            }

            $operRaw = CDataValue::toInt($statuses[self::IF_OPER_STATUS.'.'.$ifIndex] ?? null);

            $ports[] = [
                'if_index' => $ifIndex,
                'name' => sprintf('gpon 0/%d/%d', (int) $m[1], (int) $m[2]),
                'slot' => (int) $m[1],
                'port' => (int) $m[2],
                'oper_status_code' => $operRaw,
                'oper_status' => $operRaw === 1 ? 'up' : ($operRaw === 2 ? 'down' : 'unknown'),
            ];
        }

        usort($ports, fn ($a, $b) => [$a['slot'], $a['port']] <=> [$b['slot'], $b['port']]);

        return $ports;
    }

    public function getRegisteredOnus(SnmpOlt $olt): array
    {
        $hasCli = $olt->cli_transport === 'telnet' && filled($olt->cli_username);

        // Jalur utama: tabel NSCRTV 17409.2.8.4 (nama, SN, model, status, Rx — tanpa telnet).
        $onus = $this->snmpOnus($olt);
        if ($onus !== []) {
            // Enrich admin-state (dan Rx CLI yang lebih andal) bila telnet ada. Best-effort:
            // kegagalan CLI tak menggugurkan inventory SNMP yang sudah lengkap.
            if ($hasCli) {
                try {
                    $onus = $this->mergeCliDetail($onus, $this->cli->getOnts($olt));
                } catch (Throwable) {
                    // diabaikan — pertahankan hasil SNMP
                }
            }

            return $onus;
        }

        $onus = $this->legacyOnus($olt);
        if ($onus !== [] || ! $hasCli) {
            return $onus;
        }

        // Tak ada tabel ONU SNMP yang dikenali → CLI penuh.
        try {
            return $this->cli->getOnts($olt);
        } catch (Throwable) {
            return [];
        }
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
        // Rx per-ONU SNMP (di-key onu_key `slot.port.onuId`); hanya entri yang benar-benar terisi.
        $map = [];
        foreach ($this->snmpOnus($olt) as $onu) {
            if (($onu['rx_power_dbm'] ?? null) !== null) {
                $map[$onu['onu_key']] = $onu['rx_power_dbm'];
            }
        }

        return $map;
    }

    public function countRegisteredOnus(SnmpOlt $olt): int
    {
        try {
            $count = count($this->optionalWalk($olt, self::GPON_NAME));
            if ($count > 0) {
                return $count;
            }

            if ($this->isV3($olt)) {
                $count = count($this->optionalWalk($olt, self::V3_ONU_ENUM));

                return $count > 0 ? $count : count($this->optionalWalk($olt, self::V3_STATUS));
            }

            return count($this->optionalWalk($olt, self::FD_ONLINE));
        } catch (Throwable) {
            return 0;
        }
    }

    public function getUnconfiguredOnus(SnmpOlt $olt): array
    {
        return [];
    }

    public function isV3(SnmpOlt $olt): bool
    {
        try {
            return $this->snmp->walk($olt, self::V3_STATUS) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function legacyOnus(SnmpOlt $olt): array
    {
        $status = $this->optionalWalk($olt, self::FD_ONLINE);
        if ($status === []) {
            return [];
        }

        $descMap = $this->keyedByLast3($this->snmp->walk($olt, self::FD_USERINFO));
        $oltDescMap = $this->keyedByLast3($this->snmp->walk($olt, self::OLT_DESC));
        $onus = [];

        foreach ($status as $oid => $value) {
            $seg = CDataValue::oidLastSegments($oid, 3);
            if ($seg === null) {
                continue;
            }

            [$slot, $port, $onuId] = $seg;
            $key = "{$slot}.{$port}.{$onuId}";
            $online = CDataValue::toInt($value) === 1;
            $desc = $descMap[$key] ?? $oltDescMap[$key] ?? null;

            $onus[] = $this->onuRow($slot, $port, $onuId, $key, $online, $desc);
        }

        usort($onus, fn ($a, $b) => [$a['slot'], $a['port'], $a['onu_id']] <=> [$b['slot'], $b['port'], $b['onu_id']]);

        return $onus;
    }

    /**
     * Inventory ONU via tabel NSCRTV `17409.2.8.4`: nama (master, beri slot/port/onuId + label)
     * di-join per onuIndex dgn SN/vendor/model/status/sebab-down/Rx, plus MAC 17409.2.3.4.7.
     *
     * Nama & status wajib: tabel absen = kosong, tapi timeout dilempar supaya scan gagal dan cache
     * lama bertahan (bukan tiba-tiba 0 ONU / semua offline). Kolom pelengkap best-effort.
     *
     * @return array<int, array<string, mixed>>
     */
    private function snmpOnus(SnmpOlt $olt): array
    {
        $names = $this->optionalWalk($olt, self::GPON_NAME);
        if ($names === []) {
            return [];
        }

        $operByIdx = array_map(CDataValue::toInt(...), $this->columnByIndex($this->optionalWalk($olt, self::GPON_OPER), self::GPON_OPER));
        $serialByIdx = $this->columnByIndex($this->safeWalk($olt, self::GPON_SERIAL), self::GPON_SERIAL);
        $vendorByIdx = $this->columnByIndex($this->safeWalk($olt, self::GPON_VENDOR), self::GPON_VENDOR);
        $modelByIdx = $this->columnByIndex($this->safeWalk($olt, self::GPON_MODEL), self::GPON_MODEL);
        $causeByIdx = $this->columnByIndex($this->safeWalk($olt, self::GPON_DOWN_CAUSE), self::GPON_DOWN_CAUSE);
        $rxByIdx = $this->rxByIndex($olt);
        $macByIdx = $this->macByIndex($olt);

        // Tabel .21 hanya dibaca bila kolom NSCRTV-nya tak ada (lihat catatan kelas).
        [$v3StatusByIdx, $v3RxByIdx] = ($operByIdx === [] || $rxByIdx === []) ? $this->v3StatusRx($olt) : [[], []];

        $onus = [];

        foreach ($names as $oid => $rawName) {
            $idxSeg = CDataValue::oidLastSegments($oid, 1);
            $parsed = CDataValue::parseGponOnuName($rawName);
            if ($idxSeg === null || $parsed === null) {
                continue;
            }

            $onuIdx = $idxSeg[0];
            ['slot' => $slot, 'port' => $port, 'onu_id' => $onuId, 'label' => $label] = $parsed;

            if (isset($operByIdx[$onuIdx])) {
                $known = true;
                $online = $operByIdx[$onuIdx] === 1;
            } elseif (isset($v3StatusByIdx[$onuIdx])) {
                $known = true;
                $online = $v3StatusByIdx[$onuIdx] === 1;
            } else {
                $known = false;
                $online = false;
            }

            $row = $this->onuRow($slot, $port, $onuId, "{$slot}.{$port}.{$onuId}", $online, $label);
            $row['phase_state'] = $known ? ($online ? 'Online' : 'Offline') : 'Unknown';
            $row['serial_number'] = CDataValue::gponSerial($serialByIdx[$onuIdx] ?? null);
            $row['vendor_id'] = CDataValue::clean($vendorByIdx[$onuIdx] ?? null);
            $row['type_name'] = CDataValue::clean($modelByIdx[$onuIdx] ?? null);
            $row['mac'] = $macByIdx[$onuIdx] ?? null;
            $row['source'] = 'snmp';

            $cause = CDataValue::clean($causeByIdx[$onuIdx] ?? null);
            $row['last_down_cause'] = $cause === '--' ? null : $cause;

            // ONU offline masih bisa membawa nilai Rx lama/sentinel → hanya pakai Rx bila tak diketahui offline.
            $rx = ($online || ! $known) ? ($rxByIdx[$onuIdx] ?? $v3RxByIdx[$onuIdx] ?? null) : null;
            if ($rx !== null) {
                $row['rx_power_dbm'] = $rx;
                $row['rx_power_label'] = sprintf('%.2f dBm', $rx);
            }

            $onus[] = $row;
        }

        usort($onus, fn ($a, $b) => [$a['slot'], $a['port'], $a['onu_id']] <=> [$b['slot'], $b['port'], $b['onu_id']]);

        return $onus;
    }

    /**
     * Rx per onuIndex dari `17409.2.8.4.4.1.4.<idx>.<card>.<port>` (centi-dBm; segmen card/port beda
     * antar model — FD1608S `.0.0`, FD1601S `.0.<ifIndex PON>` — jadi hanya segmen pertama yang dipakai).
     *
     * @return array<int, float>
     */
    private function rxByIndex(SnmpOlt $olt): array
    {
        $map = [];

        foreach ($this->safeWalk($olt, self::GPON_RX) as $oid => $value) {
            $idx = (int) strtok($this->suffixAfter($oid, self::GPON_RX), '.');
            $rx = CDataValue::gponCentiRxDbm(CDataValue::toInt($value));
            if ($idx > 0 && $rx !== null && ! isset($map[$idx])) {
                $map[$idx] = $rx;
            }
        }

        return $map;
    }

    /**
     * MAC per onuIndex global dari `17409.2.3.4.7.1.3.<idx>.1` (Hex-STRING).
     *
     * @return array<int, string>
     */
    private function macByIndex(SnmpOlt $olt): array
    {
        $map = [];

        foreach ($this->safeWalk($olt, self::GPON_MAC) as $oid => $value) {
            $seg = CDataValue::oidLastSegments($oid, 2); // [onuIndex, 1]
            $mac = CDataValue::macFromHex($value);
            if ($seg !== null && $mac !== null) {
                $map[$seg[0]] = $mac;
            }
        }

        return $map;
    }

    /**
     * Status (online) & Rx per onuIndex dari tabel optik 34592 .21 (cadangan). col3 = onuIndex
     * penghubung, col2 = status, col5 = Rx; ketiganya dijoin lewat suffix index yang identik.
     *
     * @return array{0: array<int, int>, 1: array<int, float>} [statusByIdx, rxByIdx]
     */
    private function v3StatusRx(SnmpOlt $olt): array
    {
        try {
            $idxBySuffix = [];
            foreach ($this->snmp->walk($olt, self::V3_OPT_ONUIDX) as $oid => $value) {
                $onuIdx = CDataValue::toInt($value);
                if ($onuIdx !== null) {
                    $idxBySuffix[$this->suffixAfter($oid, self::V3_OPT_ONUIDX)] = $onuIdx;
                }
            }

            $statusByIdx = [];
            foreach ($this->snmp->walk($olt, self::V3_OPT_STATUS) as $oid => $value) {
                $onuIdx = $idxBySuffix[$this->suffixAfter($oid, self::V3_OPT_STATUS)] ?? null;
                if ($onuIdx !== null) {
                    $statusByIdx[$onuIdx] = CDataValue::toInt($value);
                }
            }

            $rxByIdx = [];
            foreach ($this->snmp->walk($olt, self::V3_OPT_RX) as $oid => $value) {
                $onuIdx = $idxBySuffix[$this->suffixAfter($oid, self::V3_OPT_RX)] ?? null;
                $rx = CDataValue::gponRxDbm($value);
                if ($onuIdx !== null && $rx !== null) {
                    $rxByIdx[$onuIdx] = $rx;
                }
            }

            return [$statusByIdx, $rxByIdx];
        } catch (Throwable) {
            return [[], []];
        }
    }

    /**
     * Tempel detail CLI (SN/admin/last-down/type + Rx andal) ke baris SNMP, di-join `slot.port.onuId`.
     * Daftar ONU & status online tetap dari SNMP; CLI hanya mengisi atribut yang lebih andal/absen.
     *
     * @param  array<int, array<string, mixed>>  $snmpOnus
     * @param  array<int, array<string, mixed>>  $cliOnus
     * @return array<int, array<string, mixed>>
     */
    private function mergeCliDetail(array $snmpOnus, array $cliOnus): array
    {
        $byKey = [];
        foreach ($cliOnus as $cli) {
            $byKey["{$cli['slot']}.{$cli['port']}.{$cli['onu_id']}"] = $cli;
        }

        foreach ($snmpOnus as &$onu) {
            $cli = $byKey["{$onu['slot']}.{$onu['port']}.{$onu['onu_id']}"] ?? null;
            if ($cli === null) {
                continue;
            }

            $onu['serial_number'] = $cli['serial_number'] ?? $onu['serial_number'];
            $onu['vendor_id'] = $cli['vendor_id'] ?? ($onu['vendor_id'] ?? null);
            $onu['admin_state'] = $cli['admin_state'] ?? $onu['admin_state'];
            $onu['last_down_cause'] = $cli['last_down_cause'] ?? $onu['last_down_cause'];
            $onu['type_name'] = $cli['type_name'] ?? $onu['type_name'];

            // Rx CLI tetap diutamakan bila ada (sumber yang diverifikasi paling lama).
            if (($cli['rx_power_dbm'] ?? null) !== null) {
                $onu['rx_power_dbm'] = $cli['rx_power_dbm'];
                $onu['rx_power_label'] = $cli['rx_power_label'] ?? $onu['rx_power_label'];
            }
        }

        return $snmpOnus;
    }

    /**
     * @return array<string, mixed>
     */
    private function onuRow(int $slot, int $port, int $onuId, string $key, bool $online, ?string $desc): array
    {
        return [
            'onu_key' => $key,
            'if_index' => $slot,
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
            'interface' => sprintf('gpon 0/%d/%d:%d', $slot, $port, $onuId),
            'type_name' => null,
            'name' => $desc,
            'description' => $desc,
            'serial_number' => null,
            'mac' => null,
            'admin_state' => 'unknown',
            'phase_state' => $online ? 'Online' : 'Offline',
            'online' => $online,
            'last_down_cause' => null,
            'rx_power_dbm' => null,
            'rx_power_label' => null,
        ];
    }

    /**
     * Walk tabel yang boleh tak ada di varian firmware tertentu: absen → `[]`, timeout tetap dilempar.
     *
     * @return array<string, string>
     */
    private function optionalWalk(SnmpOlt $olt, string $oid): array
    {
        try {
            return $this->snmp->walk($olt, $oid);
        } catch (CDataSnmpMissingOid) {
            return [];
        }
    }

    /**
     * Walk kolom pelengkap: error apa pun → `[]` (atributnya dibiarkan kosong).
     *
     * @return array<string, string>
     */
    private function safeWalk(SnmpOlt $olt, string $oid): array
    {
        try {
            return $this->snmp->walk($olt, $oid);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Kolom tabel ber-index tunggal onuIndex → `[onuIdx => nilai]`.
     *
     * @param  array<string, string>  $walk
     * @return array<int, string>
     */
    private function columnByIndex(array $walk, string $base): array
    {
        $map = [];

        foreach ($walk as $oid => $value) {
            $idx = (int) strtok($this->suffixAfter($oid, $base), '.');
            if ($idx > 0) {
                $map[$idx] = $value;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $walk
     * @return array<string, ?string> di-key oleh `slot.port.onuId`
     */
    private function keyedByLast3(array $walk): array
    {
        $map = [];

        foreach ($walk as $oid => $value) {
            $seg = CDataValue::oidLastSegments($oid, 3);
            if ($seg !== null) {
                $map[implode('.', $seg)] = CDataValue::clean($value);
            }
        }

        return $map;
    }

    private function suffixAfter(string $oid, string $base): string
    {
        $base = ltrim($base, '.').'.';
        $oid = ltrim($oid, '.');

        return str_starts_with($oid, $base) ? substr($oid, strlen($base)) : $oid;
    }
}
