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

    // C300/C320 OIDs (.1012 subtree)
    private const ZTE_ONU_TYPE = '1.3.6.1.4.1.3902.1012.3.28.1.1.1';

    private const ZTE_ONU_NAME = '1.3.6.1.4.1.3902.1012.3.28.1.1.2';

    private const ZTE_ONU_DESCRIPTION = '1.3.6.1.4.1.3902.1012.3.28.1.1.3';

    private const ZTE_ONU_SN = '1.3.6.1.4.1.3902.1012.3.28.1.1.5';

    private const ZTE_ONU_ADMIN_STATE = '1.3.6.1.4.1.3902.1012.3.28.1.1.17';

    private const ZTE_ONU_PHASE_STATE = '1.3.6.1.4.1.3902.1012.3.28.2.1.4';

    private const ZTE_ONU_LAST_DOWN_CAUSE = '1.3.6.1.4.1.3902.1012.3.28.2.1.7';

    private const ZTE_ONU_RX_POWER = '1.3.6.1.4.1.3902.1012.3.50.12.1.1.10';

    // zxAnCardTable per-board processor load (C300 & C320), index = rack.shelf.slot.
    // Mirrors the CLI `show processor` columns. Verified against OLT-C320-PATI / OLT-C300-SEKARJALAK.
    private const ZTE_CARD_CPU = '1.3.6.1.4.1.3902.1015.2.1.1.3.1.9';      // CPU usage %

    private const ZTE_CARD_MEM = '1.3.6.1.4.1.3902.1015.2.1.1.3.1.11';     // memory usage %

    private const ZTE_CARD_PHYMEM = '1.3.6.1.4.1.3902.1015.2.1.1.3.1.19';  // physical memory (MB)

    private const ZTE_UNCFG_OIDS = [
        '1.3.6.1.4.1.3902.1012.3.13.3.1.2',
        '1.3.6.1.4.1.3902.1082.500.10.2.1.1',
        '1.3.6.1.4.1.3902.1082.500.10.2.1.2',
        '1.3.6.1.4.1.3902.1082.500.10.1.1.1',
    ];

    // C600 ONU table (.1082 subtree), columns of 1082.500.20.2.1.2.1, index = {ifIndex}.{onuId}
    // where ifIndex is the real IF-MIB index of the PON port. Mapped column-by-column against a
    // live C600 (ZXA10 C600 V1.2.2) — the older 1082.500.10.2.3/.8/.11 OIDs this file used before
    // do not exist on the device at all (No Such Object), which read every C600 as zero ONUs.
    private const C600_ONU_TABLE = '1.3.6.1.4.1.3902.1082.500.20.2.1.2.1';

    // ONU model, present for every vendor (e.g. "F641", "HG8145V5"); the type gate for a C600 walk.
    private const C600_ONU_TYPE = self::C600_ONU_TABLE.'.8';

    // Serial number as an 8-byte octet string: 4 ASCII vendor chars + 4 raw bytes (ZTEG008EEB08).
    private const C600_ONU_SN = self::C600_ONU_TABLE.'.3';

    // Online state. Only ever 1 or 2 on the live C600, and the value tracks the per-ONU traffic
    // counters exactly (1 = counters advancing, 2 = frozen) across 51 ONUs on two PON ports.
    private const C600_ONU_PHASE_STATE = self::C600_ONU_TABLE.'.7';

    public const C600_PHASE_WORKING = 1;

    public const C600_PHASE_OFFLINE = 2;

    // Not mapped on the live C600: no column carries the ONU name (.19 is empty for every ONU on
    // an OLT that sets none), and no admin-state, last-down-cause or RX-power table was found
    // anywhere under 1082.500 — see docs/SMARTOLT_ZTE_C600_GUIDE.md before filling these in.
    private const C600_ONU_NAME = null;

    private const C600_ONU_ADMIN_STATE = null;

    private const C600_ONU_LAST_DOWN_CAUSE = null;

    private const C600_ONU_RX_POWER = null;

    private const C600_UNCFG_OIDS = [];

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
        $isC600 = SmartOltSupport::isC600($olt);
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
            $portLabel = $this->resolvePortLabel($name, $description, $isC600);
            if ($portLabel === null) {
                continue;
            }
            $seen[$ifIndex] = true;

            [$slot, $port] = $this->parseSlotPort($portLabel, $ifIndex, $olt);
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
            // Walk only this port's ONU-table subtree — avoids walking the whole OLT (+ the heavy
            // IF-MIB port walk) just to show one port. Safe on C600 too: its ONU table is keyed by
            // the real IF-MIB if-index, so zteEncodeIfIndex() reproduces the prefix exactly (a full
            // C600 walk measured ~151s for a single port before this was scoped).
            $scope = (string) $this->zteEncodeIfIndex($olt, $slot, $port);
            $portRow = null;
            $onus = $this->registeredOnus($olt, null, $scope);

            $onus = array_values(array_filter(
                $onus,
                fn (array $onu) => (int) $onu['slot'] === $slot && (int) $onu['port'] === $port
            ));
            $ifIndex = $onus[0]['if_index'] ?? $portRow['if_index'] ?? $this->zteEncodeIfIndex($olt, $slot, $port);
            $rxPower = [
                'ok' => true,
                'source' => 'snmp',
                'count' => 0,
                'error' => null,
            ];

            try {
                $onus = $this->mergeOnuRxPowers($onus, $this->onuRxPowers($olt, $scope));
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
                'if_index' => $this->zteEncodeIfIndex($olt, $slot, $port),
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
     * @return array<string, string|null>
     */
    private function onuOids(SnmpOlt $olt): array
    {
        if (SmartOltSupport::isC600($olt)) {
            return [
                'type' => self::C600_ONU_TYPE,
                'name' => self::C600_ONU_NAME,
                'description' => null, // no separate description column on the C600 ONU table
                'sn' => self::C600_ONU_SN,
                'admin_state' => self::C600_ONU_ADMIN_STATE,
                'phase_state' => self::C600_ONU_PHASE_STATE,
                'last_down' => self::C600_ONU_LAST_DOWN_CAUSE,
                'rx_power' => self::C600_ONU_RX_POWER,
            ];
        }

        return [
            'type' => self::ZTE_ONU_TYPE,
            'name' => self::ZTE_ONU_NAME,
            'description' => self::ZTE_ONU_DESCRIPTION,
            'sn' => self::ZTE_ONU_SN,
            'admin_state' => self::ZTE_ONU_ADMIN_STATE,
            'phase_state' => self::ZTE_ONU_PHASE_STATE,
            'last_down' => self::ZTE_ONU_LAST_DOWN_CAUSE,
            'rx_power' => self::ZTE_ONU_RX_POWER,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $ports
     * @return array<int, array<string, mixed>>
     */
    public function registeredOnus(SnmpOlt $olt, ?array $ports = null, ?string $scope = null): array
    {
        $isC600 = SmartOltSupport::isC600($olt);
        $oids = $this->onuOids($olt);

        // When $scope (an ONU-table prefix index, e.g. zteEncodeIfIndex(slot,port))
        // is given, walk only that port's subtree instead of the whole OLT — this
        // is what keeps a single-port refresh light (tens of rows vs thousands).
        $walk = fn (string $oid): array => $this->walk($olt, $scope === null ? $oid : $this->joinOid($oid, $scope));

        $types = $walk($oids['type']);
        if ($types === []) {
            return [];
        }

        // Columns a family does not expose are null (the C600 has no name/admin/last-down table).
        $walkOptional = fn (?string $oid): array => $oid === null ? [] : $walk($oid);

        $names = $walkOptional($oids['name']);
        $descriptions = $walkOptional($oids['description']);
        $serials = $walk($oids['sn']);
        $adminStates = $walkOptional($oids['admin_state']);
        $phaseStates = $walkOptional($oids['phase_state']);
        $lastDownCauses = $walkOptional($oids['last_down']);
        // Scoped walks don't need the (heavy) IF-MIB port map; C300/C320 derive
        // slot/port from the ONU-table prefix via decodeIfIndex anyway.
        $portMap = $this->buildPortMap($ports ?? ($scope === null ? $this->gponPorts($olt) : []));
        $onus = [];

        foreach ($types as $oid => $typeName) {
            $index = $this->extractOnuIndex($oid, $oids['type']);
            if ($index === null) {
                continue;
            }

            [$ifIndex, $onuId] = $index;
            $suffix = "{$ifIndex}.{$onuId}";

            // C300/C320: the ZTE ONU-table prefix index self-encodes slot/port, so
            // decode it directly. The portMap is keyed by the IF-MIB GPON-port
            // if-index — a different numbering whose slot-2 range numerically
            // collides with slot-1 ONU prefixes (ONU 1/P prefix == gpon_1/2/(P+1)
            // if-index), which would mis-bind every slot-1 ONU onto a slot-2 port.
            // C600 keeps the legacy port-map path (untested for this collision).
            if ($isC600) {
                $portRow = $portMap[$ifIndex] ?? null;
                [$slot, $port] = $portRow
                    ? [(int) $portRow['slot'], (int) $portRow['port']]
                    : $this->decodeIfIndex($olt, $ifIndex);
            } else {
                [$slot, $port] = $this->decodeIfIndex($olt, $ifIndex);
            }
            $phaseRaw = $oids['phase_state'] ? $this->intFromWalk($phaseStates, $oids['phase_state'], $suffix) : null;
            $adminRaw = $oids['admin_state'] ? $this->intFromWalk($adminStates, $oids['admin_state'], $suffix) : null;
            $lastDownRaw = $oids['last_down'] ? $this->intFromWalk($lastDownCauses, $oids['last_down'], $suffix) : null;

            $onus[] = [
                'if_index' => $ifIndex,
                'onu_id' => $onuId,
                'slot' => $slot,
                'port' => $port,
                'interface' => SmartOltSupport::onuInterfaceId($slot, $port, $onuId, $isC600),
                'type_name' => $typeName,
                'name' => $oids['name']
                    ? $this->walkValue($names, $oids['name'], $suffix)
                    : null,
                'description' => $oids['description']
                    ? $this->walkValue($descriptions, $oids['description'], $suffix)
                    : null,
                'serial_number' => $this->decodeOnuSn($this->walkValue($serials, $oids['sn'], $suffix)),
                'admin_state_code' => $adminRaw,
                'admin_state' => $this->decodeAdminState($adminRaw),
                'phase_state_code' => $phaseRaw,
                'phase_state' => $this->decodePhaseState($phaseRaw, $isC600),
                'online' => $isC600 ? $phaseRaw === self::C600_PHASE_WORKING : $phaseRaw === 3,
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
    public function onuRxPowers(SnmpOlt $olt, ?string $scope = null): array
    {
        $isC600 = SmartOltSupport::isC600($olt);
        $rxOid = $isC600 ? self::C600_ONU_RX_POWER : self::ZTE_ONU_RX_POWER;

        // No SNMP RX table was found on the live C600; callers fall back to CLI RX.
        if ($rxOid === null) {
            return [];
        }

        $rows = $this->walk($olt, $scope === null ? $rxOid : $this->joinOid($rxOid, $scope));
        $powers = [];

        foreach ($rows as $oid => $rawValue) {
            $raw = $this->intFromValue($rawValue);
            if ($raw === null) {
                continue;
            }

            if ($isC600) {
                // C600 RX power OID indexed by ifIndex.onuId (2-tuple), raw/1000 = dBm
                $index = $this->extractOnuIndex($oid, $rxOid);
                if ($index === null) {
                    continue;
                }
                [$ifIndex, $onuId] = $index;
                $onuPort = 1;
            } else {
                // C300/C320 RX power OID indexed by ifIndex.onuId.port (3-tuple)
                $index = $this->extractOnuPortIndex($oid, $rxOid);
                if ($index === null) {
                    continue;
                }
                [$ifIndex, $onuId, $onuPort] = $index;
            }

            $dbm = $this->convertOnuRxPowerToDbm($raw);
            if ($dbm === null) {
                continue;
            }

            $key = $this->onuRxPowerKey($ifIndex, $onuId);
            if (isset($powers[$key]) && ! $isC600 && $onuPort !== 1) {
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
        $isC600 = SmartOltSupport::isC600($olt);
        $uncfgOids = $isC600 ? self::C600_UNCFG_OIDS : self::ZTE_UNCFG_OIDS;
        $seen = [];
        $onus = [];

        foreach ($uncfgOids as $baseOid) {
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
                    ? $this->decodeIfIndex($olt, (int) $index['if_index'])
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
                    'port_alias' => $slot && $port
                        ? SmartOltSupport::onuInterfaceId($slot, $port, $index['onu_id'] ?? 0, $isC600)
                        : null,
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
    /**
     * Per-board (card) processor load: CPU%, memory%, physical memory (MB).
     * Keyed by "rack.shelf.slot" so callers can merge onto `show card` rows.
     * Same zxAnCardTable OIDs work on C300 and C320; boards without a CPU
     * (e.g. power cards) report phy_mem 0 and are still returned.
     *
     * @return array<string, array{cpu: ?int, mem: ?int, phy_mem: int}>
     */
    public function cardProcessors(SnmpOlt $olt): array
    {
        $columns = [
            'phy_mem' => self::ZTE_CARD_PHYMEM,
            'cpu' => self::ZTE_CARD_CPU,
            'mem' => self::ZTE_CARD_MEM,
        ];

        $byIndex = [];

        foreach ($columns as $field => $baseOid) {
            foreach ($this->walk($olt, $baseOid) as $oid => $value) {
                $index = $this->cardIndexSuffix((string) $oid, $baseOid);

                if ($index === null) {
                    continue;
                }

                $byIndex[$index][$field] = (int) $value;
            }
        }

        $result = [];

        foreach ($byIndex as $index => $vals) {
            $result[$index] = [
                'cpu' => $vals['cpu'] ?? null,
                'mem' => $vals['mem'] ?? null,
                'phy_mem' => $vals['phy_mem'] ?? 0,
            ];
        }

        return $result;
    }

    private function cardIndexSuffix(string $oid, string $baseOid): ?string
    {
        $prefix = $this->normalizeOid($baseOid).'.';
        $oid = $this->normalizeOid($oid);

        if (! str_starts_with($oid, $prefix)) {
            return null;
        }

        $suffix = substr($oid, strlen($prefix)); // "rack.shelf.slot"

        return $suffix !== '' ? $suffix : null;
    }

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
    private function parseSlotPort(string $description, int $ifIndex, SnmpOlt $olt): array
    {
        // 4-tier (C600): rack/shelf/slot/port — check before 3-tier to avoid partial match
        if (preg_match('/(\d+)\/(\d+)\/(\d+)\/(\d+)/', $description, $matches)) {
            return [(int) $matches[3], (int) $matches[4]];
        }

        // 3-tier (C300/C320): rack/slot/port
        if (preg_match('/(\d+)\/(\d+)\/(\d+)/', $description, $matches)) {
            return [(int) $matches[2], (int) $matches[3]];
        }

        return $this->decodeIfIndex($olt, $ifIndex);
    }

    private function resolvePortLabel(?string $name, ?string $description, bool $isC600 = false): ?string
    {
        foreach ([$name, $description] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            // Firmware spells the same port differently per family: C320 ifName is `gpon_1/2/1`
            // while its CLI says `gpon-olt_1/2/1`; C600 says `gpon_olt-1/3/1` in both. Capture the
            // numeric tail and re-emit the family's own CLI spelling, so the label matches what the
            // OLT prints (and a C600 name is not mangled into `gpon-olt_olt-1/3/1`).
            if (preg_match('/^gpon[-_]?(?:olt)?[-_]?(\d+(?:\/\d+){2,3})$/i', $candidate, $matches)) {
                return ($isC600 ? 'gpon_olt-' : 'gpon-olt_').$matches[1];
            }
        }

        return null;
    }

    private function zteEncodeIfIndex(SnmpOlt $olt, int $slot, int $port): int
    {
        if (SmartOltSupport::isC600($olt)) {
            // C600: (type=1<<28) | (rack=1<<24) | (shelf=1<<16) | (slot<<8) | port
            return (1 << 28) | (1 << 24) | (1 << 16) | ($slot << 8) | $port;
        }

        // C300/C320: (type=1<<28) | (slot<<16) | (port<<8)
        return 0x10000000 | ($slot << 16) | ($port << 8);
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function decodeIfIndex(SnmpOlt $olt, int $ifIndex): array
    {
        if (SmartOltSupport::isC600($olt)) {
            // C600: slot at bits 15–8, port at bits 7–0
            return [
                ($ifIndex >> 8) & 0xFF,
                $ifIndex & 0xFF,
            ];
        }

        // C300/C320: slot at bits 23–16, port at bits 15–8
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
        // 0xFFFF (65535) = "N/A" sentinel; other hard sentinels for negative-encoded firmwares.
        if ($raw <= -80000 || $raw >= 2147480000 || $raw === -32768 || $raw === 65535) {
            return null;
        }

        if ($raw >= -50000 && $raw <= -3000) {
            return round($raw / 1000, 3);
        }

        if ($raw >= -500 && $raw <= -5) {
            return round($raw / 10, 3);
        }

        if ($raw > 0 && $raw <= 65534) {
            // ZTE C300/C320 ONU-RX: unsigned 16-bit, dBm = signed16(raw) * 0.002 - 30.
            // raw 32768..65534 are negative two's-complement (weak signal),
            // e.g. raw 64032 = -1504 = -33.0 dBm. Window drops impossible/garbage values.
            $signed = $raw > 32767 ? $raw - 65536 : $raw;
            $dbm = round(($signed * 0.002) - 30, 3);

            return ($dbm >= -45.0 && $dbm <= 0.0) ? $dbm : null;
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

    private function decodePhaseState(?int $code, bool $isC600 = false): string
    {
        if ($isC600) {
            // The C600 column is a plain online flag, not the C300 phase enum: it reports only
            // 1 or 2 and never distinguishes LOS/DyingGasp/AuthFailed. Do not invent those codes
            // here — a wrong guess is what previously made every C600 ONU read as offline.
            return match ($code) {
                self::C600_PHASE_WORKING => 'Working',
                self::C600_PHASE_OFFLINE => 'Offline',
                default => 'Unknown',
            };
        }

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
