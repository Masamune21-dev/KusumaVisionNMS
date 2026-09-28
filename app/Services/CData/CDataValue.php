<?php

namespace App\Services\CData;

/**
 * Helper parsing murni untuk driver C-Data (EPON 17409 & GPON).
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
     * Hex-STRING MAC (`D0 5F AF 00 00 01` atau `0xD05FAF000001`) → `D0:5F:AF:00:00:01`.
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

    /**
     * Parse onuName GPON dari tabel legacy `17409.2.8.4.1.1.2`:
     * `gpon <chassis>/<slot>/<port> onu <onuId> <deskripsi>` (chassis diabaikan, samakan dgn ifDescr).
     *
     * @return array{slot: int, port: int, onu_id: int, label: ?string}|null
     */
    public static function parseGponOnuName(?string $name): ?array
    {
        $name = self::clean($name);

        // Firmware kadang mengisi sisa field nama dengan NUL + sampah → net-snmp mengirimnya sbg
        // Hex-STRING (terlihat di FD1608S: `67 70 6F 6E …` = "gpon 0/0/5 onu 6 …\0\0\0ZTE").
        // Decode, potong di NUL pertama; dulu baris ini gagal diparse dan ONU-nya hilang dari NMS.
        if ($name !== null && preg_match('/^(?:[0-9A-Fa-f]{2}\s+)+[0-9A-Fa-f]{2}$/', $name)) {
            $decoded = (string) hex2bin(preg_replace('/\s+/', '', $name) ?? '');
            $nul = strpos($decoded, "\0");
            $name = self::clean(mb_scrub($nul === false ? $decoded : substr($decoded, 0, $nul), 'UTF-8'));
        }

        if ($name === null || ! preg_match('/gpon\s+\d+\/(\d+)\/(\d+)\s+onu\s+(\d+)\s*(.*)$/i', $name, $m)) {
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

    /**
     * Serial ONU GPON dari `onuSerialNum` (`17409.2.8.4.1.1.3`, OCTET STRING 8 byte): 4 byte vendor
     * ASCII + 4 byte hex → bentuk CLI `ZTEG1A2B3C4D`. net-snmp menampilkannya sebagai Hex-STRING
     * (`5A 54 45 47 1A 2B 3C 4D`) bila ada byte tak tercetak, atau STRING 8 karakter bila semuanya
     * tercetak — keduanya ditangani. Nilai yang sudah berbentuk `VVVVXXXXXXXX` diteruskan apa adanya.
     */
    public static function gponSerial(?string $raw): ?string
    {
        $raw = self::clean($raw);
        if ($raw === null) {
            return null;
        }

        if (preg_match('/^(?:[0-9A-Fa-f]{2}\s+){7}[0-9A-Fa-f]{2}$/', $raw)) {
            $bytes = hex2bin(preg_replace('/\s+/', '', $raw) ?? '');
        } elseif (strlen($raw) === 8) {
            $bytes = $raw;
        } elseif (preg_match('/^[A-Za-z0-9]{4}[0-9A-Fa-f]{8}$/', $raw)) {
            return strtoupper($raw);
        } else {
            return null;
        }

        $vendor = substr((string) $bytes, 0, 4);
        if (! preg_match('/^[A-Za-z0-9]{4}$/', $vendor)) {
            return null;
        }

        return strtoupper($vendor.bin2hex(substr((string) $bytes, 4, 4)));
    }

    /**
     * Rx ONU GPON dari tabel NSCRTV `17409.2.8.4.4.1.4` (INTEGER centi-dBm, mis. `-2495` → -24.95).
     * ONU tanpa pembacaan dilaporkan `-1` (terlihat pada ONU offline) atau `0` → null; di luar
     * jendela Rx masuk akal juga dibuang.
     */
    public static function gponCentiRxDbm(?int $raw): ?float
    {
        if ($raw === null || $raw >= -1) {
            return null;
        }

        $dbm = round($raw / 100, 2);

        return ($dbm >= -60.0 && $dbm <= 5.0) ? $dbm : null;
    }

    /**
     * Rx ONU GPON V3 dari tabel optik `34592.1.5.1.1.2.21.1.1.5` (string dBm langsung, `--` = N/A).
     * Tabel ini sering kosong/fluktuatif di FD1608S — buang `--` dan nilai di luar jendela masuk akal.
     */
    public static function gponRxDbm(?string $value): ?float
    {
        $value = self::clean($value);
        if ($value === null || ! preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
            return null;
        }

        $dbm = round((float) $m[0], 2);

        // Jendela Rx ONU GPON yang masuk akal; buang sentinel/garbage (mis. nilai positif besar).
        return ($dbm >= -60.0 && $dbm <= 5.0) ? $dbm : null;
    }
}
