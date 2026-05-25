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

    private function normalizeValue(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('/^[A-Z][A-Za-z0-9\- ]+:\s*/', '', $value) ?? $value;
        $value = trim($value, "\" \t\n\r\0\x0B");

        return $value === '' ? null : $value;
    }
}
