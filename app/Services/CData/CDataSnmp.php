<?php

namespace App\Services\CData;

use App\Models\SnmpOlt;
use RuntimeException;
use SNMP;

/**
 * Koneksi SNMP read low-level untuk perangkat C-Data (v1/v2c).
 *
 * Output OID dipaksa numerik (SNMP_OID_OUTPUT_NUMERIC) karena driver 34592 melakukan
 * OID-suffix matching (guide §2.1). Method dibuat overridable supaya parsing di driver
 * bisa diuji unit dengan data walk sintetis.
 */
class CDataSnmp
{
    public function get(SnmpOlt $olt, string $oid): ?string
    {
        $this->assertV2($olt);

        $session = $this->session($olt, 3_000_000);

        try {
            $value = @$session->get($oid);
        } finally {
            $session->close();
        }

        return $value === false ? null : CDataValue::clean((string) $value);
    }

    /**
     * @return array<string, string> di-key oleh OID numerik (tanpa titik depan)
     */
    public function walk(SnmpOlt $olt, string $oid): array
    {
        $this->assertV2($olt);

        $session = $this->session($olt, 5_000_000);
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
            $normalized[ltrim((string) $rowOid, '.')] = CDataValue::clean((string) $value) ?? '';
        }

        return $normalized;
    }

    private function session(SnmpOlt $olt, int $timeoutUs): SNMP
    {
        $version = $olt->snmp_version === 'v1' ? SNMP::VERSION_1 : SNMP::VERSION_2C;
        $session = new SNMP($version, $olt->getHostAddress(), $olt->snmp_read_community, $timeoutUs, 2);
        $session->valueretrieval = SNMP_VALUE_LIBRARY;
        $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;

        return $session;
    }

    private function assertV2(SnmpOlt $olt): void
    {
        if ($olt->snmp_version === 'v3') {
            throw new RuntimeException('C-Data hanya mendukung SNMP v1/v2c.');
        }
    }
}
