<?php

namespace App\Services\Snmp;

use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use RuntimeException;
use SNMP;
use Throwable;

class OltSnmpClient
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    private const ZTE_ONU_TYPE = '1.3.6.1.4.1.3902.1012.3.28.1.1.1';

    private const ZTE_ONU_NAME = '1.3.6.1.4.1.3902.1012.3.28.1.1.2';

    private const ZTE_ONU_DESCRIPTION = '1.3.6.1.4.1.3902.1012.3.28.1.1.3';

    private const ZTE_ONU_SN = '1.3.6.1.4.1.3902.1012.3.28.1.1.5';

    private const ZTE_ONU_ADMIN_STATE = '1.3.6.1.4.1.3902.1012.3.28.1.1.17';

    private const ZTE_ONU_PHASE_STATE = '1.3.6.1.4.1.3902.1012.3.28.2.1.4';

    private const ZTE_ONU_LAST_DOWN_CAUSE = '1.3.6.1.4.1.3902.1012.3.28.2.1.7';

    private const ZTE_ONU_RX_POWER = '1.3.6.1.4.1.3902.1012.3.50.12.1.1.10';

    private const ZTE_UNCFG_OIDS = [
        '1.3.6.1.4.1.3902.1012.3.13.3.1.2',
        '1.3.6.1.4.1.3902.1082.500.10.2.1.1',
        '1.3.6.1.4.1.3902.1082.500.10.2.1.2',
        '1.3.6.1.4.1.3902.1082.500.10.1.1.1',
    ];

    /**
     * @return array<string, mixed>
     */
    public function test(SnmpOlt $olt): array
    {
        $startedAt = microtime(true);

        try {
            $system = $this->systemInfo($olt);

            return [
                'ok' => true,
                'driver' => SmartOltSupport::driverKey(
                    $olt,
                    $system['sys_descr'] ?? null,
                    $system['sys_object_id'] ?? null,
                ),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'system' => $system,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'driver' => SmartOltSupport::driverKey($olt),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'system' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(SnmpOlt $olt): array
    {
        $startedAt = microtime(true);

        try {
            $system = $this->systemInfo($olt);
            $ports = $this->gponPorts($olt);
            $driver = SmartOltSupport::driverKey(
                $olt,
                $system['sys_descr'] ?? null,
                $system['sys_object_id'] ?? null,
            );

            return [
                'ok' => true,
                'driver' => $driver,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'system' => $system,
                'ports' => $ports,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'driver' => SmartOltSupport::driverKey($olt),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'system' => [],
                'ports' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, string|null>
     */
    public function systemInfo(SnmpOlt $olt): array
    {
        return [
            'sys_descr' => $this->get($olt, self::SYS_DESCR),
            'sys_object_id' => $this->get($olt, self::SYS_OBJECT_ID),
            'sys_uptime' => $this->get($olt, self::SYS_UPTIME),
            'sys_name' => $this->get($olt, self::SYS_NAME),
        ];
    }

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        if ($olt->snmp_version === 'v3') {
            throw new RuntimeException('SNMP v3 belum didukung pada tester awal.');
        }

        if (class_exists(SNMP::class)) {
            $version = $olt->snmp_version === 'v1' ? SNMP::VERSION_1 : SNMP::VERSION_2C;
            $session = new SNMP($version, $olt->getHostAddress(), $olt->snmp_read_community, 3_000_000, 2);
            $session->valueretrieval = SNMP_VALUE_LIBRARY;
            $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;

            try {
                $value = @$session->get($oid);
            } finally {
                $session->close();
            }

            if ($value === false) {
                throw new RuntimeException("SNMP get failed for {$oid}");
            }

            return $this->normalizeValue((string) $value);
        }

        $function = $olt->snmp_version === 'v1' ? 'snmpget' : 'snmp2_get';
        $value = @$function($olt->getHostAddress(), $olt->snmp_read_community, $oid, 3_000_000, 2);

        if ($value === false) {
            throw new RuntimeException("SNMP get failed for {$oid}");
        }

        return $this->normalizeValue((string) $value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function gponPorts(SnmpOlt $olt): array
    {
        $descriptions = $this->walk($olt, self::IF_DESCR);
        $names = $this->walk($olt, self::IF_NAME);
        $statuses = $this->walk($olt, self::IF_OPER_STATUS);
        $ports = [];
        $seen = [];

        foreach ($names + $descriptions as $oid => $label) {
            $ifIndex = $this->extractIndex($oid, self::IF_DESCR);
            $ifIndex ??= $this->extractIndex($oid, self::IF_NAME);
            if ($ifIndex === null) {
                continue;
            }
            if (isset($seen[$ifIndex])) {
                continue;
            }

            $description = $descriptions[$this->joinOid(self::IF_DESCR, (string) $ifIndex)] ?? null;
            $name = $names[$this->joinOid(self::IF_NAME, (string) $ifIndex)] ?? null;
            $portLabel = $this->resolvePortLabel($name, $description);
            if ($portLabel === null) {
                continue;
            }
            $seen[$ifIndex] = true;

            [$slot, $port] = $this->parseSlotPort($portLabel, $ifIndex);
            $operRaw = $statuses[$this->joinOid(self::IF_OPER_STATUS, (string) $ifIndex)] ?? null;
            $operCode = $operRaw !== null ? (int) preg_replace('/\D+/', '', $operRaw) : null;

            $ports[] = [
                'if_index' => $ifIndex,
                'name' => $portLabel,
                'if_name' => $name,
                'if_descr' => $description,
                'slot' => $slot,
                'port' => $port,
                'oper_status_code' => $operCode,
                'oper_status' => $this->decodeOperStatus($operCode),
            ];
        }

        usort($ports, fn (array $a, array $b) => [$a['slot'], $a['port'], $a['if_index']] <=> [$b['slot'], $b['port'], $b['if_index']]);

        return $ports;
    }

    /**
     * @return array<string, mixed>
     */
    public function portOnusSnapshot(SnmpOlt $olt, int $slot, int $port): array
    {
        $startedAt = microtime(true);

        try {
            $ports = $this->gponPorts($olt);
            $portRow = collect($ports)->first(
                fn (array $row) => (int) $row['slot'] === $slot && (int) $row['port'] === $port
            );

            $allOnus = $this->registeredOnus($olt, $ports);
            $onus = array_values(array_filter(
                $allOnus,
                fn (array $onu) => (int) $onu['slot'] === $slot && (int) $onu['port'] === $port
            ));
            $ifIndex = $onus[0]['if_index'] ?? $portRow['if_index'] ?? $this->zteEncodeIfIndex($slot, $port);
            $rxPower = [
                'ok' => true,
                'source' => 'snmp',
                'count' => 0,
                'error' => null,
            ];

            try {
                $onus = $this->mergeOnuRxPowers($onus, $this->onuRxPowers($olt));
                $rxPower['count'] = $this->countSnmpRxPowers($onus);
            } catch (Throwable $exception) {
                $rxPower = [
                    'ok' => false,
                    'source' => 'snmp',
                    'count' => 0,
                    'error' => $exception->getMessage(),
                ];
            }

            return [
                'ok' => true,
                'slot' => $slot,
                'port' => $port,
                'if_index' => (int) $ifIndex,
                'port_row' => $portRow,
                'onus' => $onus,
                'count' => count($onus),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'rx_power' => $rxPower,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'slot' => $slot,
                'port' => $port,
                'if_index' => $this->zteEncodeIfIndex($slot, $port),
                'port_row' => null,
                'onus' => [],
                'count' => 0,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'rx_power' => [
                    'ok' => false,
                    'source' => 'snmp',
                    'count' => 0,
                    'error' => $exception->getMessage(),
                ],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $ports
     * @return array<int, array<string, mixed>>
     */
    public function registeredOnus(SnmpOlt $olt, ?array $ports = null): array
    {
        $types = $this->walk($olt, self::ZTE_ONU_TYPE);
        if ($types === []) {
            return [];
        }

        $names = $this->walk($olt, self::ZTE_ONU_NAME);
        $descriptions = $this->walk($olt, self::ZTE_ONU_DESCRIPTION);
        $serials = $this->walk($olt, self::ZTE_ONU_SN);
        $adminStates = $this->walk($olt, self::ZTE_ONU_ADMIN_STATE);
        $phaseStates = $this->walk($olt, self::ZTE_ONU_PHASE_STATE);
        $lastDownCauses = $this->walk($olt, self::ZTE_ONU_LAST_DOWN_CAUSE);
        $portMap = $this->buildPortMap($ports ?? $this->gponPorts($olt));
        $onus = [];

        foreach ($types as $oid => $typeName) {
            $index = $this->extractOnuIndex($oid, self::ZTE_ONU_TYPE);
            if ($index === null) {
                continue;
            }

            [$ifIndex, $onuId] = $index;
            $suffix = "{$ifIndex}.{$onuId}";
            $portRow = $portMap[$ifIndex] ?? null;
            [$slot, $port] = $portRow
                ? [(int) $portRow['slot'], (int) $portRow['port']]
                : $this->decodeIfIndex($ifIndex);
            $phaseRaw = $this->intFromWalk($phaseStates, self::ZTE_ONU_PHASE_STATE, $suffix);
            $adminRaw = $this->intFromWalk($adminStates, self::ZTE_ONU_ADMIN_STATE, $suffix);
            $lastDownRaw = $this->intFromWalk($lastDownCauses, self::ZTE_ONU_LAST_DOWN_CAUSE, $suffix);

            $onus[] = [
                'if_index' => $ifIndex,
                'onu_id' => $onuId,
                'slot' => $slot,
                'port' => $port,
                'interface' => sprintf('gpon-onu_1/%d/%d:%d', $slot, $port, $onuId),
                'type_name' => $typeName,
                'name' => $this->walkValue($names, self::ZTE_ONU_NAME, $suffix),
                'description' => $this->walkValue($descriptions, self::ZTE_ONU_DESCRIPTION, $suffix),
                'serial_number' => $this->decodeOnuSn($this->walkValue($serials, self::ZTE_ONU_SN, $suffix)),
                'admin_state_code' => $adminRaw,
                'admin_state' => $this->decodeAdminState($adminRaw),
                'phase_state_code' => $phaseRaw,
                'phase_state' => $this->decodePhaseState($phaseRaw),
                'online' => $phaseRaw === 3,
                'last_down_cause_code' => $lastDownRaw,
                'last_down_cause' => $this->decodeLastDownCause($lastDownRaw),
            ];
        }

        usort($onus, fn (array $a, array $b) => [$a['slot'], $a['port'], $a['onu_id']] <=> [$b['slot'], $b['port'], $b['onu_id']]);

        return $onus;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function onuRxPowers(SnmpOlt $olt): array
    {
        $rows = $this->walk($olt, self::ZTE_ONU_RX_POWER);
        $powers = [];

        foreach ($rows as $oid => $rawValue) {
            $index = $this->extractOnuPortIndex($oid, self::ZTE_ONU_RX_POWER);
            $raw = $this->intFromValue($rawValue);

            if ($index === null || $raw === null) {
                continue;
            }

            [$ifIndex, $onuId, $onuPort] = $index;
            $dbm = $this->convertOnuRxPowerToDbm($raw);

            if ($dbm === null) {
                continue;
            }

            $key = $this->onuRxPowerKey($ifIndex, $onuId);
            if (isset($powers[$key]) && $onuPort !== 1) {
                continue;
            }

            $powers[$key] = [
                'if_index' => $ifIndex,
                'onu_id' => $onuId,
                'rx_power_port' => $onuPort,
                'raw_rx_power' => $raw,
                'rx_power_dbm' => $dbm,
                'rx_power_label' => sprintf('%.3f dBm', $dbm),
                'rx_power_source' => 'snmp_onu_rx',
            ];
        }

        return $powers;
    }

    /**
     * @param  array<int, array<string, mixed>>  $onus
     * @param  array<string, array<string, mixed>>  $powers
     * @return array<int, array<string, mixed>>
     */
    public function mergeOnuRxPowers(array $onus, array $powers): array
    {
        return array_map(function (array $onu) use ($powers) {
            $ifIndex = (int) ($onu['if_index'] ?? 0);
            $onuId = (int) ($onu['onu_id'] ?? 0);
            $power = $powers[$this->onuRxPowerKey($ifIndex, $onuId)] ?? null;

            if ($power === null) {
                return $onu;
            }

            return [
                ...$onu,
                'rx_power_port' => $power['rx_power_port'],
                'raw_rx_power' => $power['raw_rx_power'],
                'rx_power_dbm' => $power['rx_power_dbm'],
                'rx_power_label' => $power['rx_power_label'],
                'rx_power_source' => $power['rx_power_source'],
            ];
        }, $onus);
    }

    /**
     * @return array<string, mixed>
     */
    public function unconfiguredOnusSnapshot(SnmpOlt $olt): array
    {
        $startedAt = microtime(true);

        try {
            $rows = $this->unconfiguredOnus($olt);

            return [
                'ok' => true,
                'count' => count($rows),
                'onus' => $rows,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'count' => 0,
                'onus' => [],
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function unconfiguredOnus(SnmpOlt $olt): array
    {
        $seen = [];
        $onus = [];

        foreach (self::ZTE_UNCFG_OIDS as $baseOid) {
            try {
                $rows = $this->walk($olt, $baseOid);
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $oid => $rawValue) {
                $sn = $this->decodeOnuSn($rawValue);
                if ($sn === null || strlen($sn) < 8 || strlen($sn) > 16) {
                    continue;
                }

                if (isset($seen[$sn])) {
                    continue;
                }

                $index = $this->extractUnconfiguredIndex($oid, $baseOid);
                [$slot, $port] = $index['if_index'] !== null
                    ? $this->decodeIfIndex((int) $index['if_index'])
                    : [null, null];

                $seen[$sn] = true;
                $onus[] = [
                    'serial_number' => $sn,
                    'raw_value' => $rawValue,
                    'oid' => $oid,
                    'source_oid' => $baseOid,
                    'oid_index' => $index['suffix'],
                    'if_index' => $index['if_index'],
                    'suggested_onu_id' => $index['onu_id'],
                    'slot' => $slot,
                    'port' => $port,
                    'port_alias' => $slot && $port ? sprintf('gpon-onu_1/%d/%d:%d', $slot, $port, $index['onu_id'] ?? 0) : null,
                ];
            }

            if ($onus !== []) {
                break;
            }
        }

        usort($onus, fn (array $a, array $b) => [$a['slot'] ?? 999, $a['port'] ?? 999, $a['serial_number']] <=> [$b['slot'] ?? 999, $b['port'] ?? 999, $b['serial_number']]);

        return $onus;
    }

    /**
     * Write a single OID via SNMP SET using the write community.
     *
     * @param  string  $type  single-char SNMP type ('i' integer, 's' string)
     */
    public function set(SnmpOlt $olt, string $oid, string $type, string $value): bool
    {
        if ($olt->snmp_version === 'v3') {
            throw new RuntimeException('SNMP v3 belum didukung pada writer awal.');
        }

        $community = $olt->snmp_write_community;

        if ($community === null || $community === '') {
            throw new RuntimeException('SNMP write community OLT wajib diisi untuk operasi tulis.');
        }

        if (class_exists(SNMP::class)) {
            $version = $olt->snmp_version === 'v1' ? SNMP::VERSION_1 : SNMP::VERSION_2C;
            $session = new SNMP($version, $olt->getHostAddress(), $community, 3_000_000, 2);

            try {
                $ok = @$session->set($oid, $type, $value);
            } finally {
                $session->close();
            }

            if ($ok === false) {
                throw new RuntimeException("SNMP set failed for {$oid}");
            }

            return true;
        }

        $function = $olt->snmp_version === 'v1' ? 'snmpset' : 'snmp2_set';
        $ok = @$function($olt->getHostAddress(), $community, $oid, $type, $value, 3_000_000, 2);

        if ($ok === false) {
            throw new RuntimeException("SNMP set failed for {$oid}");
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function walk(SnmpOlt $olt, string $oid): array
    {
        if ($olt->snmp_version === 'v3') {
            throw new RuntimeException('SNMP v3 belum didukung pada walker awal.');
        }

        if (class_exists(SNMP::class)) {
            $version = $olt->snmp_version === 'v1' ? SNMP::VERSION_1 : SNMP::VERSION_2C;
            $session = new SNMP($version, $olt->getHostAddress(), $olt->snmp_read_community, 5_000_000, 2);
            $session->valueretrieval = SNMP_VALUE_LIBRARY;
            $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
            $session->oid_increasing_check = false;
            $session->max_oids = 10;

            try {
                $rows = @$session->walk($oid);
            } finally {
                $session->close();
            }

            if (! is_array($rows)) {
                throw new RuntimeException("SNMP walk failed for {$oid}");
            }

            return $this->normalizeWalkRows($rows);
        }

        $function = $olt->snmp_version === 'v1' ? 'snmprealwalk' : 'snmp2_real_walk';
        $rows = @$function($olt->getHostAddress(), $olt->snmp_read_community, $oid, 5_000_000, 2);

        if (! is_array($rows)) {
            throw new RuntimeException("SNMP walk failed for {$oid}");
        }

        return $this->normalizeWalkRows($rows);
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return array<string, string>
     */
    private function normalizeWalkRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $oid => $value) {
            $normalized[$this->normalizeOid((string) $oid)] = $this->normalizeValue((string) $value) ?? '';
        }

        return $normalized;
    }

    private function normalizeOid(string $oid): string
    {
        return ltrim($oid, '.');
    }

    private function joinOid(string $base, string $suffix): string
    {
        return $this->normalizeOid($base).'.'.ltrim($suffix, '.');
    }

    private function extractIndex(string $oid, string $base): ?int
    {
        $oid = $this->normalizeOid($oid);
        $base = $this->normalizeOid($base).'.';

        if (! str_starts_with($oid, $base)) {
            return null;
        }

        $suffix = substr($oid, strlen($base));

        return ctype_digit($suffix) ? (int) $suffix : null;
    }

    /**
     * @return array{0:int, 1:int}|null
     */
    private function extractOnuIndex(string $oid, string $base): ?array
    {
        $oid = $this->normalizeOid($oid);
        $base = $this->normalizeOid($base).'.';

        if (! str_starts_with($oid, $base)) {
            return null;
        }

        $parts = explode('.', substr($oid, strlen($base)));
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * @return array{0:int, 1:int, 2:int}|null
     */
    private function extractOnuPortIndex(string $oid, string $base): ?array
    {
        $oid = $this->normalizeOid($oid);
        $base = $this->normalizeOid($base).'.';

        if (! str_starts_with($oid, $base)) {
            return null;
        }

        $parts = explode('.', substr($oid, strlen($base)));
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return null;
        }

        return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
    }

    /**
     * @return array{suffix:string|null, if_index:int|null, onu_id:int|null}
     */
    private function extractUnconfiguredIndex(string $oid, string $base): array
    {
        $oid = $this->normalizeOid($oid);
        $base = $this->normalizeOid($base).'.';

        if (! str_starts_with($oid, $base)) {
            return ['suffix' => null, 'if_index' => null, 'onu_id' => null];
        }

        $suffix = substr($oid, strlen($base));
        $parts = array_values(array_filter(explode('.', $suffix), fn (string $part) => ctype_digit($part)));

        if (count($parts) >= 2) {
            return [
                'suffix' => $suffix,
                'if_index' => (int) $parts[count($parts) - 2],
                'onu_id' => (int) $parts[count($parts) - 1],
            ];
        }

        if (count($parts) === 1) {
            return [
                'suffix' => $suffix,
                'if_index' => (int) $parts[0],
                'onu_id' => null,
            ];
        }

        return ['suffix' => $suffix, 'if_index' => null, 'onu_id' => null];
    }

    /**
     * @return array{0:int|null, 1:int|null}
     */
    private function parseSlotPort(string $description, int $ifIndex): array
    {
        if (preg_match('/(\d+)\/(\d+)\/(\d+)/', $description, $matches)) {
            return [(int) $matches[2], (int) $matches[3]];
        }

        return [
            ($ifIndex >> 16) & 0xFF,
            ($ifIndex >> 8) & 0xFF,
        ];
    }

    private function resolvePortLabel(?string $name, ?string $description): ?string
    {
        foreach ([$name, $description] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (preg_match('/^gpon(?:[-_]olt)?[-_]?\d+\/\d+\/\d+$/i', $candidate)) {
                // Normalise to canonical gpon-olt_ format so SNMP names always
                // match CLI-persisted names (e.g. C300 reports gpon_1/2/1 via SNMP
                // but its CLI uses gpon-olt_1/2/1).
                return preg_replace('/^gpon_/i', 'gpon-olt_', $candidate) ?? $candidate;
            }
        }

        return null;
    }

    private function zteEncodeIfIndex(int $slot, int $port): int
    {
        return 0x10000000 | ($slot << 16) | ($port << 8);
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function decodeIfIndex(int $ifIndex): array
    {
        return [
            ($ifIndex >> 16) & 0xFF,
            ($ifIndex >> 8) & 0xFF,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $ports
     * @return array<int, array<string, mixed>>
     */
    private function buildPortMap(array $ports): array
    {
        $map = [];

        foreach ($ports as $port) {
            $map[(int) $port['if_index']] = $port;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function walkValue(array $rows, string $base, string $suffix): ?string
    {
        return $rows[$this->joinOid($base, $suffix)] ?? null;
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function intFromWalk(array $rows, string $base, string $suffix): ?int
    {
        return $this->intFromValue($this->walkValue($rows, $base, $suffix));
    }

    private function intFromValue(?string $value): ?int
    {
        if ($value === null || ! preg_match('/-?\d+/', $value, $matches)) {
            return null;
        }

        return (int) $matches[0];
    }

    private function convertOnuRxPowerToDbm(int $raw): ?float
    {
        if ($raw <= -80000 || $raw >= 2147480000 || $raw >= 65000 || $raw === -32768) {
            return null;
        }

        if ($raw >= -50000 && $raw <= -3000) {
            return round($raw / 1000, 3);
        }

        if ($raw >= -500 && $raw <= -5) {
            return round($raw / 10, 3);
        }

        if ($raw > 0) {
            return round(($raw * 0.002) - 30, 3);
        }

        return null;
    }

    private function onuRxPowerKey(int $ifIndex, int $onuId): string
    {
        return "{$ifIndex}.{$onuId}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $onus
     */
    private function countSnmpRxPowers(array $onus): int
    {
        return count(array_filter(
            $onus,
            fn (array $onu) => str_starts_with((string) ($onu['rx_power_source'] ?? ''), 'snmp')
                && ($onu['rx_power_dbm'] ?? null) !== null,
        ));
    }

    private function decodeOnuSn(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = trim($raw, "\" \t\n\r\0\x0B");

        if (preg_match('/^([0-9A-Fa-f]{2}\s+){7}[0-9A-Fa-f]{2}$/', $raw)) {
            $bytes = preg_split('/\s+/', $raw) ?: [];
            $vendor = implode('', array_map(fn (string $hex) => chr(hexdec($hex)), array_slice($bytes, 0, 4)));

            if (preg_match('/^[A-Z]{4}$/', $vendor)) {
                return strtoupper($vendor.implode('', array_slice($bytes, 4, 4)));
            }
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    private function decodeAdminState(?int $code): string
    {
        return match ($code) {
            1 => 'active',
            2 => 'disabled',
            default => 'unknown',
        };
    }

    private function decodePhaseState(?int $code): string
    {
        return match ($code) {
            0 => 'Logging',
            1 => 'LOS',
            2 => 'Sync MIB',
            3 => 'Working',
            4 => 'DyingGasp',
            5 => 'Auth Failed',
            6 => 'Offline',
            default => 'Unknown',
        };
    }

    private function decodeLastDownCause(?int $code): string
    {
        return match ($code) {
            0 => 'Normal',
            1 => 'LOS',
            2 => 'LOSi',
            3 => 'LOFi',
            4 => 'SFi',
            5 => 'LOAi',
            6 => 'LOAMi',
            7 => 'Deactivated',
            8 => 'Manual',
            9 => 'DyingGasp',
            default => 'Unknown',
        };
    }

    private function decodeOperStatus(?int $code): string
    {
        return match ($code) {
            1 => 'up',
            2 => 'down',
            3 => 'testing',
            4 => 'unknown',
            5 => 'dormant',
            6 => 'notPresent',
            7 => 'lowerLayerDown',
            default => 'unknown',
        };
    }

    private function normalizeValue(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('/^[A-Z][A-Za-z0-9\- ]+:\s*/', '', $value) ?? $value;
        $value = trim($value, "\" \t\n\r\0\x0B");

        return $value === '' ? null : $value;
    }
}
