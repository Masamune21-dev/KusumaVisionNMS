<?php

namespace App\Services\Hsgq;

use App\Models\SnmpOlt;
use RuntimeException;
use SNMP;

/**
 * Transport SNMP read (v1/v2c) khusus HSGQ 50224. Berdiri sendiri seperti HiOSO/C-Data.
 *
 * Quirk perangkat (peta OID G02ID): GET ke kolom `ifTable` dijawab `No Such Instance` walau WALK
 * berhasil — karena itu semua tabel dibaca lewat walk/GETBULK, GET hanya untuk skalar persis.
 * `oid_increasing_check` sengaja DIBIARKAN aktif: agen OLT murah pernah membalas GETNEXT dengan OID
 * yang sama berulang (OEM 12170), dan pemeriksaan ini yang menghentikan walk alih-alih berputar.
 * Method dibuat overridable untuk pengujian unit tanpa perangkat.
 */
class HsgqSnmp
{
    private const TIMEOUT_US = 5_000_000;

    private const RETRIES = 2;

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        $session = $this->session($olt);

        try {
            $value = @$session->get($oid);
        } finally {
            $session->close();
        }

        return $value === false ? null : HsgqValue::clean((string) $value);
    }

    /**
     * Walk satu kolom/subtree. Subtree yang tak ada di agen → `[]` (bukan error): driver HSGQ
     * memang mencoba dua skema tabel ONU dan memakai yang berisi. Perangkat tak menjawab →
     * RuntimeException.
     *
     * @return array<string, string> di-key oleh OID numerik (tanpa titik depan)
     */
    public function walk(SnmpOlt $olt, string $oid): array
    {
        $session = $this->session($olt);

        try {
            $rows = @$session->walk($oid);
            $errno = $session->getErrno();
        } finally {
            $session->close();
        }

        if (! is_array($rows)) {
            if ($errno === SNMP::ERRNO_ERROR_IN_REPLY || $errno === SNMP::ERRNO_OID_NOT_INCREASING) {
                return [];
            }

            throw new RuntimeException(__('hsgq.snmp_walk_failed', ['oid' => $oid]));
        }

        $normalized = [];
        foreach ($rows as $rowOid => $value) {
            $normalized[ltrim((string) $rowOid, '.')] = HsgqValue::clean((string) $value) ?? '';
        }

        return $normalized;
    }

    private function session(SnmpOlt $olt): SNMP
    {
        // OLT demo tak punya perangkat — jangan pernah membuka sesi SNMP.
        if ($olt->is_demo) {
            throw new RuntimeException(__('hsgq.demo_device'));
        }

        if ($olt->snmp_version === 'v3') {
            throw new RuntimeException(__('hsgq.snmp_v3_unsupported'));
        }

        $version = $olt->snmp_version === 'v1' ? SNMP::VERSION_1 : SNMP::VERSION_2C;
        $session = new SNMP($version, $olt->getHostAddress(), (string) $olt->snmp_read_community, self::TIMEOUT_US, self::RETRIES);
        $session->valueretrieval = SNMP_VALUE_LIBRARY;
        $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;

        return $session;
    }
}
