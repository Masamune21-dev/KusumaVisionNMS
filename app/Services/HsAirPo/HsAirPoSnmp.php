<?php

namespace App\Services\HsAirPo;

use App\Models\SnmpOlt;
use RuntimeException;
use SNMP;

/**
 * Transport SNMP read (v1/v2c) untuk OLT HsAirPo / HSGQ (OEM Photon Broadband, enterprise 12170).
 *
 * Berdiri sendiri (tak memakai transport C-Data/HiOSO) agar family ini punya tuning sendiri.
 * Dipakai HANYA untuk MIB-2 (system + ifTable) dan **GET skalar vendor** — agen 12170 punya cabang
 * yang GETNEXT-nya loop tak berhingga (`.1.3.6.1.4.1.12170.2.3.1.2`, juga `.2.3.2.4`), jadi
 * **JANGAN walk subtree enterprise**; ambil nilai vendor lewat `get()` pada OID persis
 * (lihat `docs/SMARTOLT_HSAIRPO_GUIDE.md` §2).
 *
 * Inventori ONU tidak tersedia di SNMP mana pun pada perangkat ini → dibaca via CLI
 * ({@see HsAirPoCliService}).
 */
class HsAirPoSnmp
{
    private const DEFAULT_TIMEOUT_US = 5_000_000;

    private const DEFAULT_RETRIES = 2;

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        $this->assertReadable($olt);

        $session = $this->session($olt, self::DEFAULT_TIMEOUT_US, self::DEFAULT_RETRIES);

        try {
            $value = @$session->get($oid);
        } finally {
            $session->close();
        }

        return $value === false ? null : $this->clean((string) $value);
    }

    /**
     * Walk sebuah subtree. Hanya untuk OID MIB-2 standar (ifTable) — jangan dipakai pada subtree
     * enterprise 12170 yang GETNEXT-nya bug.
     *
     * @return array<string, string> di-key OID numerik (tanpa titik depan)
     */
    public function walk(SnmpOlt $olt, string $oid): array
    {
        $this->assertReadable($olt);

        $session = $this->session($olt, self::DEFAULT_TIMEOUT_US, self::DEFAULT_RETRIES);
        $session->max_oids = 20;

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
            $normalized[ltrim((string) $rowOid, '.')] = $this->clean((string) $value) ?? '';
        }

        return $normalized;
    }

    /**
     * Buang pembungkus tipe/kutip dari nilai SNMP (`STRING: "pon1"` → `pon1`).
     */
    public function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $value = preg_replace('/^(STRING|INTEGER|Gauge32|Counter32|Counter64|Timeticks|IpAddress|OID|Hex-STRING):\s*/i', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"");

        return $value === '' ? null : $value;
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
            throw new RuntimeException('HsAirPo hanya mendukung SNMP v1/v2c.');
        }
    }
}
