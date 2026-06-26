<?php

namespace App\Services\CData;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use Throwable;

/**
 * Driver SNMP read C-Data native GPON (enterprise `34592`).
 *
 * Tiga skema tabel ONU yang dipakai (terverifikasi di FD1608S V3, full walk — lihat
 * docs/handbook/17-cdata-gpon-snmp-walk.md):
 *  - Legacy/V2: FD-ONU-MIB `34592.1.3.4.1.1.<col>`, index = 3 segmen terakhir `slot.port.onuId`.
 *  - Inventory V3 ANDAL: tabel legacy `17409.2.8.4.1.1.2` (nama lengkap `gpon F/S/P onu N <label>`)
 *    + `17409.2.3.4.7.1.3.<idx>.1` (MAC), di-key oleh onuIndex global (`0x480000 + seq`).
 *  - Status/optik V3: `34592.1.5.1.1.2.21.1.1.<col>` (col2=status 1/-1, col3=onuIndex penghubung,
 *    col5=Rx dBm string). col3 menjembatani tabel optik ke tabel nama/MAC 17409.
 *
 * Quirk V3: tabel atribut `34592...18.12` hanya balas ~2 baris (tak terpakai). Inventory penuh
 * 34 ONU diambil via SNMP (cepat, tanpa telnet). SN tak tersedia via SNMP di firmware V3 →
 * di-enrich best-effort lewat CLI (`show ont info all`) bila kredensial telnet ada; Rx per-ONU SNMP
 * jarang terisi (mostly `--`) → CLI (`show ont optical-info`) tetap sumber Rx andal. v1 read-only.
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

    // Inventory V3 andal (tabel legacy 17409), di-key onuIndex global `0x480000 + seq`.
    private const GPON_NAME = '1.3.6.1.4.1.17409.2.8.4.1.1.2';   // "gpon F/S/P onu N <label>"

    private const GPON_MAC = '1.3.6.1.4.1.17409.2.3.4.7.1.3';    // Hex-STRING, suffix `<idx>.1`

    // Tabel optik/status V3 (34592 .21.1.1.<col>), index `.1.0.<port>.<onuSeq>.1` sama antar-kolom.
    private const V3_OPT_STATUS = '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2';   // 1 = online, -1 = offline

    private const V3_OPT_ONUIDX = '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3';   // onuIndex penghubung ke 17409

    private const V3_OPT_RX = '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.5';       // Rx dBm string (sering `--`)

    // Tabel statistik per-ONU `.18.26.1` — nilainya `-1` (tak berguna utk atribut), TAPI meng-enumerasi
    // seluruh ONU (1 baris/ONU). Dipakai utk hitung jumlah ONU V3 yang benar; atribut tetap dari CLI.
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

            // FD1608S/FD1216S sering laporkan sysObjectID 17409 walau GPON — konfirmasi via tabel
            // V3 atau tabel ONU legacy yang merespons.
            return $this->isV3($olt) || $this->snmp->walk($olt, self::FD_ONLINE) !== [];
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
        if (! $this->isV3($olt)) {
            return $this->legacyOnus($olt);
        }

        // V3: inventory penuh via SNMP (tabel nama/MAC 17409 + status 34592 .21) — 34 ONU, ringan,
        // tanpa telnet. (Tabel atribut V3 .18.12 hanya balas ~2 baris.)
        $onus = $this->snmpOnus($olt);

        // Enrich SN/admin/last-down/Rx-andal via CLI bila kredensial telnet ada (SN tak ada via SNMP).
        // Best-effort: kegagalan CLI tak menggugurkan inventory SNMP yang sudah lengkap.
        if ($onus !== [] && $olt->cli_transport === 'telnet' && filled($olt->cli_username)) {
            try {
                $onus = $this->mergeCliDetail($onus, $this->cli->getOnts($olt));
            } catch (Throwable) {
                // diabaikan — pertahankan hasil SNMP
            }

            return $onus;
        }

        // Fallback ekstrem: SNMP kosong tapi telnet tersedia → coba CLI penuh.
        if ($onus === [] && $olt->cli_transport === 'telnet' && filled($olt->cli_username)) {
            try {
                return $this->cli->getOnts($olt);
            } catch (Throwable) {
                return [];
            }
        }

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
        if (! $this->isV3($olt)) {
            return [];
        }

        // V3: Rx per-ONU dari tabel optik 34592 .21 col5 — sering `--`, jadi hanya entri yang
        // benar-benar terisi yang dikembalikan (di-key onu_key `slot.port.onuId`).
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
            if (! $this->isV3($olt)) {
                return count($this->snmp->walk($olt, self::FD_ONLINE));
            }

            // Tabel atribut V3 (.18.12) cuma 1 baris → pakai enumerasi penuh (.18.26).
            $count = count($this->snmp->walk($olt, self::V3_ONU_ENUM));

            return $count > 0 ? $count : count($this->snmp->walk($olt, self::V3_STATUS));
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
        $status = $this->snmp->walk($olt, self::FD_ONLINE);
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
     * Inventory ONU V3 via SNMP murni: tabel nama 17409 (master, beri slot/port/onuId + label) di-join
     * dgn MAC 17409 dan status/Rx 34592 .21 lewat onuIndex global. Lengkap 34 ONU tanpa telnet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function snmpOnus(SnmpOlt $olt): array
    {
        $names = $this->snmp->walk($olt, self::GPON_NAME);
        if ($names === []) {
            return [];
        }

        $macByIdx = $this->macByIndex($olt);
        [$statusByIdx, $rxByIdx] = $this->v3StatusRx($olt);
        $onus = [];

        foreach ($names as $oid => $rawName) {
            $idxSeg = CDataValue::oidLastSegments($oid, 1);
            $parsed = CDataValue::parseGponOnuName($rawName);
            if ($idxSeg === null || $parsed === null) {
                continue;
            }

            $onuIdx = $idxSeg[0];
            ['slot' => $slot, 'port' => $port, 'onu_id' => $onuId, 'label' => $label] = $parsed;

            $statusRaw = $statusByIdx[$onuIdx] ?? null;
            $online = $statusRaw === 1;

            $row = $this->onuRow($slot, $port, $onuId, "{$slot}.{$port}.{$onuId}", $online, $label);
            $row['mac'] = $macByIdx[$onuIdx] ?? null;
            $row['phase_state'] = $statusRaw === null ? 'Unknown' : ($online ? 'Online' : 'Offline');
            $row['source'] = 'snmp';
            $row['v3'] = true;

            $rx = $rxByIdx[$onuIdx] ?? null;
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
     * MAC per onuIndex global dari `17409.2.3.4.7.1.3.<idx>.1` (Hex-STRING).
     *
     * @return array<int, string>
     */
    private function macByIndex(SnmpOlt $olt): array
    {
        $map = [];

        foreach ($this->snmp->walk($olt, self::GPON_MAC) as $oid => $value) {
            $seg = CDataValue::oidLastSegments($oid, 2); // [onuIndex, 1]
            $mac = CDataValue::macFromHex($value);
            if ($seg !== null && $mac !== null) {
                $map[$seg[0]] = $mac;
            }
        }

        return $map;
    }

    /**
     * Status (online) & Rx per onuIndex dari tabel optik 34592 .21. col3 = onuIndex penghubung,
     * col2 = status, col5 = Rx; ketiganya dijoin lewat suffix index yang identik.
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
     * Daftar ONU tetap dari SNMP (lengkap); CLI hanya mengisi atribut yang tak tersedia via SNMP.
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

            // Rx CLI lebih andal daripada SNMP (.21 sering `--`) → utamakan bila ada.
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
