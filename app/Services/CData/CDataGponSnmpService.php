<?php

namespace App\Services\CData;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use Throwable;

/**
 * Driver SNMP read C-Data native GPON (enterprise `34592`).
 *
 * Dua skema tabel ONU (guide §3.2/§3.3 & §5):
 *  - Legacy/V2: FD-ONU-MIB `34592.1.3.4.1.1.<col>`, index = 3 segmen terakhir `slot.port.onuId`.
 *  - FlashV3.x: `34592.1.5.1.1.2.18.12.1.<col>`, index `.1.0.<ifIndex>.<flow>.<onuId>`.
 *
 * Quirk V3: SNMP sering hanya balas 1 baris; SN/MAC/optical/inventory penuh hanya via CLI
 * (`show ont info all`) — di-enrich pada fase CLI (2c). Rx per-ONU tak tersedia via SNMP di
 * kedua skema (DDM SNMP hanya per-port OLT). v1 read-only.
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

    private const V3_NAME = '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.10';

    private const V3_DESC = '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.11';

    public function __construct(private readonly CDataSnmp $snmp) {}

    public function ping(SnmpOlt $olt): bool
    {
        try {
            $oid = $this->snmp->get($olt, self::SYS_OBJECT_ID);

            return $oid !== null && str_contains($oid, '34592');
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
        return $this->isV3($olt) ? $this->v3Onus($olt) : $this->legacyOnus($olt);
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
        // Rx per-ONU GPON tidak tersedia via SNMP (DDM hanya per-port OLT); V3 via CLI (2c).
        return [];
    }

    public function countRegisteredOnus(SnmpOlt $olt): int
    {
        try {
            return count($this->snmp->walk($olt, $this->isV3($olt) ? self::V3_STATUS : self::FD_ONLINE));
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
     * @return array<int, array<string, mixed>>
     */
    private function v3Onus(SnmpOlt $olt): array
    {
        $status = $this->snmp->walk($olt, self::V3_STATUS);
        if ($status === []) {
            return [];
        }

        $ifMap = $this->gponIfMap($olt);
        $names = $this->snmp->walk($olt, self::V3_NAME);
        $descs = $this->snmp->walk($olt, self::V3_DESC);
        $onus = [];

        foreach ($status as $oid => $value) {
            // index `.1.0.<ifIndex>.<flow>.<onuId>`
            $seg = CDataValue::oidLastSegments($oid, 3);
            if ($seg === null) {
                continue;
            }

            [$ifIndex, $flow, $onuId] = $seg;
            $suffix = $this->suffixAfter($oid, self::V3_STATUS);
            $slotPort = $ifMap[$ifIndex] ?? ['slot' => 0, 'port' => 0];
            $online = CDataValue::toInt($value) === 1;

            $name = CDataValue::clean($names[self::V3_NAME.'.'.$suffix] ?? null);
            $desc = CDataValue::clean($descs[self::V3_DESC.'.'.$suffix] ?? null);

            $row = $this->onuRow($slotPort['slot'], $slotPort['port'], $onuId, "{$ifIndex}.{$flow}.{$onuId}", $online, $desc ?? $name);
            $row['v3'] = true;
            $onus[] = $row;
        }

        usort($onus, fn ($a, $b) => [$a['slot'], $a['port'], $a['onu_id']] <=> [$b['slot'], $b['port'], $b['onu_id']]);

        return $onus;
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
     * ifDescr `gpon 0/<slot>/<port>` → ifIndex => slot/port.
     *
     * @return array<int, array{slot: int, port: int}>
     */
    private function gponIfMap(SnmpOlt $olt): array
    {
        $map = [];

        foreach ($this->snmp->walk($olt, self::IF_DESCR) as $oid => $label) {
            if (! preg_match('/gpon\s+\d+\/(\d+)\/(\d+)/i', $label, $m)) {
                continue;
            }

            $ifIndex = (CDataValue::oidLastSegments($oid, 1) ?? [null])[0];
            if ($ifIndex !== null) {
                $map[$ifIndex] = ['slot' => (int) $m[1], 'port' => (int) $m[2]];
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
