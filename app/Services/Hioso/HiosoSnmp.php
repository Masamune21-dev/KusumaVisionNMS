<?php

namespace App\Services\Hioso;

use App\Models\SnmpOlt;
use RuntimeException;
use SNMP;

/**
 * Transport SNMP read (v1/v2c) khusus HiOSO / V-Sol. Berdiri sendiri (tak memakai transport C-Data)
 * agar HiOSO punya tuning & lifecycle sendiri.
 *
 * HiOSO sering diakses lewat WAN → default timeout/retry lebih tinggi (10s/3) supaya walk tabel besar
 * tidak kembali partial (guide §2.1). OID output dipaksa numerik karena index ONU diambil dari dua
 * segmen terakhir OID (`{PON}.{ONU}`). Method dibuat overridable untuk pengujian unit tanpa perangkat.
 */
class HiosoSnmp
{
    private const DEFAULT_TIMEOUT_US = 10_000_000;

    private const DEFAULT_RETRIES = 3;

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        $this->assertReadable($olt);

        $session = $this->session($olt, 5_000_000, 2);

        try {
            $value = @$session->get($oid);
        } finally {
            $session->close();
        }

        return $value === false ? null : HiosoValue::clean((string) $value);
    }

    /**
     * @return array<string, string> di-key oleh OID numerik (tanpa titik depan)
     */
    public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = self::DEFAULT_TIMEOUT_US, int $retries = self::DEFAULT_RETRIES): array
    {
        $this->assertReadable($olt);

        $session = $this->session($olt, $timeoutUs, $retries);
        $session->oid_increasing_check = false;
        $session->max_oids = 10;

        try {
            $rows = @$session->walk($oid);
        } finally {
            $session->close();
        }

        if (! is_array($rows)) {
            throw new RuntimeException("SNMP walk gagal untuk {$oid}");
        }

        $normalized = [];
        foreach ($rows as $rowOid => $value) {
            $normalized[ltrim((string) $rowOid, '.')] = HiosoValue::clean((string) $value) ?? '';
        }

        return $normalized;
    }

    private function session(SnmpOlt $olt, int $timeoutUs, int $retries): SNMP
    {
        $version = $olt->snmp_version === 'v1' ? SNMP::VERSION_1 : SNMP::VERSION_2C;
        $session = new SNMP($version, $olt->getHostAddress(), $olt->snmp_read_community, $timeoutUs, $retries);
        $session->valueretrieval = SNMP_VALUE_LIBRARY;
        $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;

        return $session;
    }

    private function assertReadable(SnmpOlt $olt): void
    {
        if ($olt->snmp_version === 'v3') {
            throw new RuntimeException('HiOSO hanya mendukung SNMP v1/v2c.');
        }
    }
}
