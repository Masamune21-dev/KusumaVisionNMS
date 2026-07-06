<?php

namespace App\Services\Hioso;

/**
 * Helper parsing murni untuk driver HiOSO / V-Sol (bebas efek samping, mudah diuji unit).
 * Sengaja berdiri sendiri — tidak memakai helper C-Data — agar HiOSO tak terpengaruh perubahan C-Data.
 */
class HiosoValue
{
    /**
     * Bersihkan nilai SNMP: buang prefix tipe textual (`STRING: `), kutip, dan whitespace.
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $value = preg_replace('/^(?:STRING|Hex-STRING|INTEGER|Gauge32|Counter(?:32|64)?|Timeticks|IpAddress|OID|OBJECT IDENTIFIER|BITS):\s*/', '', $value) ?? $value;
        $value = trim($value, "\" \t\n\r\0\x0B");

        return $value === '' ? null : $value;
    }

    /**
     * Hex MAC 12-karakter tanpa separator (`ec237bd78071`) atau sudah ber-`:`/`-` → `EC:23:7B:D7:80:71`.
     */
    public static function macFromHex(?string $raw): ?string
    {
        $raw = self::clean($raw);
        if ($raw === null) {
            return null;
        }

        if (preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/', $raw)) {
            return strtoupper(str_replace('-', ':', $raw));
        }

        $hex = preg_replace('/^0x/i', '', $raw) ?? $raw;
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';

        if (strlen($hex) !== 12) {
            return null;
        }

        return strtoupper(implode(':', str_split($hex, 2)));
    }

    /**
     * Ambil N segmen numerik terakhir dari OID (untuk index `{PON}.{ONU}`).
     *
     * @return array<int, int>|null
     */
    public static function oidLastSegments(string $oid, int $n): ?array
    {
        $segments = array_values(array_filter(explode('.', trim($oid, '.')), 'is_numeric'));

        if (count($segments) < $n) {
            return null;
        }

        return array_map('intval', array_slice($segments, -$n));
    }

    /**
     * Rx ONU HiOSO: string dBm langsung (`"-20.36"`). `"na"`/empty/`0`/di luar jendela → null (offline).
     */
    public static function rxDbm(?string $raw): ?float
    {
        $clean = strtolower((string) self::clean($raw));

        if ($clean === '' || $clean === 'na' || $clean === 'n/a' || ! is_numeric($clean)) {
            return null;
        }

        $dbm = round((float) $clean, 2);

        return ($dbm !== 0.0 && $dbm >= -60.0 && $dbm <= 5.0) ? $dbm : null;
    }
}
