<?php

namespace App\Services\Snmp;

use App\Models\SnmpOlt;
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
                'driver' => \App\Support\SmartOltSupport::driverKey(
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
                'driver' => \App\Support\SmartOltSupport::driverKey($olt),
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
            $driver = \App\Support\SmartOltSupport::driverKey(
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
                'driver' => \App\Support\SmartOltSupport::driverKey($olt),
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
        $statuses = $this->walk($olt, self::IF_OPER_STATUS);
        $ports = [];

        foreach ($descriptions as $oid => $description) {
            if (! preg_match('/gpon.{0,2}olt/i', $description)) {
                continue;
            }

            $ifIndex = $this->extractIndex($oid, self::IF_DESCR);
            if ($ifIndex === null) {
                continue;
            }

            [$slot, $port] = $this->parseSlotPort($description, $ifIndex);
            $operRaw = $statuses[$this->joinOid(self::IF_OPER_STATUS, (string) $ifIndex)] ?? null;
            $operCode = $operRaw !== null ? (int) preg_replace('/\D+/', '', $operRaw) : null;

            $ports[] = [
                'if_index' => $ifIndex,
                'name' => $description,
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
     * @param array<string, mixed> $rows
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
