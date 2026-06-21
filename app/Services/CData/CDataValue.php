<?php

namespace App\Services\CData;

/**
 * Helper parsing murni untuk driver C-Data (EPON 17409 & GPON 34592).
 *
 * Dipisah dari koneksi SNMP supaya logika decode index/MAC/optical yang rawan bug
 * bisa diuji unit tanpa perangkat. Semua method bebas efek samping.
 */
class CDataValue
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
        // Buang prefix tipe SNMP textual bila ada (ketat — jangan memangkas nama pelanggan ber-":").
        $value = preg_replace('/^(?:STRING|Hex-STRING|INTEGER|Gauge32|Counter(?:32|64)?|Timeticks|IpAddress|OID|OBJECT IDENTIFIER|BITS):\s*/', '', $value) ?? $value;
        // net-snmp kadang menambah anotasi hex octet-string, mis. `25AR(0x32354152)` → `25AR`.
        $value = preg_replace('/\s*\(0x[0-9A-Fa-f]+\)\s*$/', '', $value) ?? $value;
        $value = trim($value, "\" \t\n\r\0\x0B");

        return $value === '' ? null : $value;
    }

    public static function toInt(?string $value): ?int
    {
        if ($value === null || ! preg_match('/-?\d+/', $value, $m)) {
            return null;
        }

        return (int) $m[0];
    }

    /**
     * Hex-STRING MAC (`D0 5F AF 63 0F 2F` atau `0xD05FAF630F2F`) → `D0:5F:AF:63:0F:2F`.
     * Bila sudah ber-`:`/`-`, normalisasi separator & uppercase.
     */
    public static function macFromHex(?string $raw): ?string
    {
        $raw = self::clean($raw);
        if ($raw === null) {
            return null;
        }

        // Bentuk yang sudah pakai separator.
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
     * Optical Rx EPON (`17409.2.3.4.2.1.4`): raw centi-dBm → dBm (`raw/100`).
     * raw == 0 ⇒ no signal (ONU offline / fiber putus) → null.
     */
    public static function eponRxDbm(?int $raw): ?float
    {
        if ($raw === null || $raw === 0) {
            return null;
        }

        $dbm = round($raw / 100, 2);

        // Jendela masuk akal untuk Rx ONU GPON/EPON; buang sentinel/garbage.
        return ($dbm >= -60.0 && $dbm <= 5.0) ? $dbm : null;
    }

    /**
     * Ambil N segmen numerik terakhir dari OID (untuk index `slot.port.onuId`, dll).
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
     * Decode device-index EPON 32-bit (guide §3.1) → slot/port/onuId.
     * Dipakai sebagai fallback bila string onuName tak terparse.
     *
     * @return array{slot: int, port: int, onu_id: int}
     */
    public static function eponDecodeDeviceIndex(int $deviceIndex): array
    {
        $slot = ($deviceIndex >> 24) & 0xFF;
        $encPort = ($deviceIndex >> 8) & 0xFF;

        return [
            'slot' => $slot,
            'port' => intdiv($encPort, 0x10) + 1,
            'onu_id' => $deviceIndex & 0xFF,
        ];
    }

    /**
     * Parse onuName EPON: `epon 0/<slot>/<port> onu <onuId> <deskripsi>` (guide §3.1 — jalur andal).
     *
     * @return array{slot: int, port: int, onu_id: int, label: ?string}|null
     */
    public static function parseEponOnuName(?string $name): ?array
    {
        $name = self::clean($name);
        if ($name === null || ! preg_match('/epon\s+0\/(\d+)\/(\d+)\s+onu\s+(\d+)\s*(.*)$/i', $name, $m)) {
            return null;
        }

        $label = trim($m[4]);

        return [
            'slot' => (int) $m[1],
            'port' => (int) $m[2],
            'onu_id' => (int) $m[3],
            'label' => $label === '' ? null : $label,
        ];
    }
}
