<?php

namespace App\Services\CData;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use Throwable;

/**
 * Driver SNMP read C-Data / ODM EPON (enterprise `17409`).
 *
 * Tabel ONU `17409.2.3.4.1.1.<col>` di-index device-index 32-bit; slot/port/onuId paling andal
 * diambil dari string onuName (kolom .2), fallback decode bitwise (guide §3.1 & §4.2).
 * Rx optical `17409.2.3.4.2.1.4` = centi-dBm. v1 read-only.
 */
class CDataEponSnmpService implements SmartOltSnmpDriver
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    private const ONU_NAME = '1.3.6.1.4.1.17409.2.3.4.1.1.2';

    private const ONU_MAC = '1.3.6.1.4.1.17409.2.3.4.1.1.7';

    private const ONU_STATUS = '1.3.6.1.4.1.17409.2.3.4.1.1.8';

    private const ONU_VENDOR_ID = '1.3.6.1.4.1.17409.2.3.4.1.1.25';

    private const ONU_MODEL_ID = '1.3.6.1.4.1.17409.2.3.4.1.1.26';

    private const ONU_SERIAL = '1.3.6.1.4.1.17409.2.3.4.1.1.28';

    private const ONU_RX = '1.3.6.1.4.1.17409.2.3.4.2.1.4';

    public function __construct(private readonly CDataSnmp $snmp) {}

    public function ping(SnmpOlt $olt): bool
    {
        try {
            $oid = $this->snmp->get($olt, self::SYS_OBJECT_ID);

            return $oid !== null && str_contains($oid, '17409');
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
        ];
    }

    public function getPorts(SnmpOlt $olt): array
    {
        $descrs = $this->snmp->walk($olt, self::IF_DESCR);
        $statuses = $this->snmp->walk($olt, self::IF_OPER_STATUS);
        $ports = [];

        foreach ($descrs as $oid => $label) {
            if (! preg_match('/epon\s+0\/(\d+)\/(\d+)/i', $label, $m)) {
                continue;
            }

            $segments = CDataValue::oidLastSegments($oid, 1);
            $ifIndex = $segments[0] ?? null;
            if ($ifIndex === null) {
                continue;
            }

            $operRaw = CDataValue::toInt($statuses[self::IF_OPER_STATUS.'.'.$ifIndex] ?? null);

            $ports[] = [
                'if_index' => $ifIndex,
                'name' => sprintf('epon 0/%d/%d', (int) $m[1], (int) $m[2]),
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
        $names = $this->snmp->walk($olt, self::ONU_NAME);
        if ($names === []) {
            return [];
        }

        $macs = $this->snmp->walk($olt, self::ONU_MAC);
        $statuses = $this->snmp->walk($olt, self::ONU_STATUS);
        $vendors = $this->snmp->walk($olt, self::ONU_VENDOR_ID);
        $models = $this->snmp->walk($olt, self::ONU_MODEL_ID);
        $serials = $this->snmp->walk($olt, self::ONU_SERIAL);
        $rxMap = $this->rxMap($olt);

        $onus = [];

        foreach ($names as $oid => $nameVal) {
            $deviceIndex = $this->deviceIndexFrom($oid, self::ONU_NAME);
            if ($deviceIndex === null) {
                continue;
            }

            $parsed = CDataValue::parseEponOnuName($nameVal) ?? CDataValue::eponDecodeDeviceIndex($deviceIndex) + ['label' => null];
            $online = CDataValue::toInt($statuses[self::ONU_STATUS.'.'.$deviceIndex] ?? null) === 1;

            $serial = CDataValue::clean($serials[self::ONU_SERIAL.'.'.$deviceIndex] ?? null);
            $mac = CDataValue::macFromHex($macs[self::ONU_MAC.'.'.$deviceIndex] ?? null);
            $rx = $rxMap[$deviceIndex] ?? null;

            $onus[] = [
                'onu_key' => (string) $deviceIndex,
                'device_index' => $deviceIndex,
                'if_index' => $deviceIndex,
                'slot' => $parsed['slot'],
                'port' => $parsed['port'],
                'onu_id' => $parsed['onu_id'],
                'interface' => sprintf('epon 0/%d/%d onu %d', $parsed['slot'], $parsed['port'], $parsed['onu_id']),
                'type_name' => CDataValue::clean($models[self::ONU_MODEL_ID.'.'.$deviceIndex] ?? null),
                'name' => $parsed['label'] ?? null,
                'description' => null,
                'serial_number' => $serial !== null ? strtoupper($serial) : $mac,
                'mac' => $mac,
                'vendor_id' => CDataValue::clean($vendors[self::ONU_VENDOR_ID.'.'.$deviceIndex] ?? null),
                'admin_state' => 'unknown',
                'phase_state' => $online ? 'Online' : 'Offline',
                'online' => $online,
                'last_down_cause' => null,
                'rx_power_dbm' => $rx,
                'rx_power_label' => $rx !== null ? sprintf('%.2f dBm', $rx) : null,
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
        $map = [];
        foreach ($this->rxMap($olt) as $deviceIndex => $dbm) {
            $map[(string) $deviceIndex] = $dbm;
        }

        return $map;
    }

    public function countRegisteredOnus(SnmpOlt $olt): int
    {
        try {
            return count($this->snmp->walk($olt, self::ONU_NAME));
        } catch (Throwable) {
            return 0;
        }
    }

    public function getUnconfiguredOnus(SnmpOlt $olt): array
    {
        // EPON 17409: discovery unconfigured belum dipetakan andal — fitur kandidat (guide §4.4).
        return [];
    }

    /**
     * @return array<int, float> deviceIndex => Rx dBm
     */
    private function rxMap(SnmpOlt $olt): array
    {
        $map = [];

        foreach ($this->snmp->walk($olt, self::ONU_RX) as $oid => $value) {
            // index Rx = `.<deviceIndex>.<x>.<y>` (guide §3.1)
            $segments = CDataValue::oidLastSegments($oid, 3);
            if ($segments === null) {
                continue;
            }

            $deviceIndex = $segments[0];
            if (isset($map[$deviceIndex])) {
                continue;
            }

            $dbm = CDataValue::eponRxDbm(CDataValue::toInt($value));
            if ($dbm !== null) {
                $map[$deviceIndex] = $dbm;
            }
        }

        return $map;
    }

    private function deviceIndexFrom(string $oid, string $base): ?int
    {
        $base = ltrim($base, '.').'.';
        $oid = ltrim($oid, '.');

        if (! str_starts_with($oid, $base)) {
            return null;
        }

        $suffix = substr($oid, strlen($base));

        return ctype_digit($suffix) ? (int) $suffix : null;
    }
}
