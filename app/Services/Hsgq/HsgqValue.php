<?php

namespace App\Services\Hsgq;

/**
 * Helper parsing murni driver HSGQ (enterprise 50224) — bebas efek samping, mudah diuji unit.
 * Berdiri sendiri (tak memakai helper C-Data/HiOSO) supaya perubahan family lain tak merembet.
 *
 * Rumus indeks (peta OID G02ID, dicocokkan ke web UI): indeks PON = `16777216 + PON×256`
 * (`0x01000000 | PON<<8`; PON01 = 16777472), indeks ONU = indeks PON + nomor ONU di UI.
 */
class HsgqValue
{
    private const INDEX_BASE = 0x01000000;

    /** Bersihkan nilai SNMP: buang prefix tipe textual (`STRING: `), kutip, dan whitespace. */
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

    public static function toInt(?string $value): ?int
    {
        $clean = self::clean($value);

        // Timeticks tampil `(123) 0:00:01.23` — ambil angka dalam kurung.
        if ($clean !== null && preg_match('/^\((\d+)\)/', $clean, $m)) {
            return (int) $m[1];
        }

        return $clean !== null && preg_match('/^-?\d+$/', $clean) ? (int) $clean : null;
    }

    public static function ponIndex(int $pon): int
    {
        return self::INDEX_BASE | ($pon << 8);
    }

    /**
     * Uraikan indeks ONU/PON 50224 → [PON, ONU]. Null bila bukan indeks berpola `0x01xxxxxx`.
     * ONU GPON boleh bernomor 0.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function decodeIndex(int $index): ?array
    {
        if (($index & 0xFF000000) !== self::INDEX_BASE) {
            return null;
        }

        $pon = ($index >> 8) & 0xFF;

        return $pon >= 1 ? [$pon, $index & 0xFF] : null;
    }

    /**
     * Segmen numerik OID setelah prefix kolom (mis. `…12.3.1.4.` → `16777473.0.0`).
     *
     * @return list<int>
     */
    public static function suffix(string $oid, string $column): array
    {
        $oid = ltrim($oid, '.');
        $column = rtrim(ltrim($column, '.'), '.').'.';

        if (! str_starts_with($oid, $column)) {
            return [];
        }

        return array_map('intval', explode('.', substr($oid, strlen($column))));
    }

    /**
     * Firmware OLT `.3.1.1.6.0` dikirim sebagai OCTET STRING yang sering tampil Hex-STRING
     * (`49 47 43 5F …` = `IGC_…`). Teks biasa diteruskan apa adanya.
     */
    public static function text(?string $raw): ?string
    {
        $clean = self::clean($raw);
        if ($clean === null) {
            return null;
        }

        if (preg_match('/^(?:[0-9A-Fa-f]{2}\s+)+[0-9A-Fa-f]{2}$/', $clean)) {
            $decoded = rtrim((string) hex2bin(preg_replace('/\s+/', '', $clean) ?? ''), "\0");

            return preg_match('/^[\x20-\x7E]+$/', $decoded) ? $decoded : $clean;
        }

        return $clean;
    }

    /**
     * Identitas ONU dari kolom serial (GPON) atau MAC (EPON): mengembalikan
     * `['serial' => 'HWTCA9089620', 'mac' => null]` atau `['serial' => 'AA:BB:…', 'mac' => 'AA:BB:…']`.
     * Menerima Hex-STRING 8 byte (4 byte vendor ASCII + 4 byte), STRING 12 karakter, MAC 6 byte,
     * maupun MAC berpemisah. `$preferMac` untuk kolom MAC (skema EPON): teks 12 hex dibaca sebagai MAC,
     * bukan SN.
     *
     * @return array{serial: ?string, mac: ?string}
     */
    public static function identity(?string $raw, bool $preferMac = false): array
    {
        $none = ['serial' => null, 'mac' => null];
        $clean = self::clean($raw);
        if ($clean === null) {
            return $none;
        }

        $serialText = preg_match('/^[A-Za-z0-9]{4}[0-9A-Fa-f]{8}$/', $clean) === 1;
        $mac = ($preferMac || ! $serialText) ? self::mac($clean) : null;
        if ($mac !== null) {
            return ['serial' => $mac, 'mac' => $mac];
        }

        if (preg_match('/^(?:[0-9A-Fa-f]{2}\s+){7}[0-9A-Fa-f]{2}$/', $clean)) {
            $bytes = (string) hex2bin(preg_replace('/\s+/', '', $clean) ?? '');
        } elseif (strlen($clean) === 8) {
            $bytes = $clean;
        } elseif (preg_match('/^[A-Za-z0-9]{4}[0-9A-Fa-f]{8}$/', $clean)) {
            return ['serial' => strtoupper($clean), 'mac' => null];
        } else {
            return $none;
        }

        $vendor = substr($bytes, 0, 4);
        if (! preg_match('/^[A-Za-z0-9]{4}$/', $vendor)) {
            return $none;
        }

        return ['serial' => strtoupper($vendor.bin2hex(substr($bytes, 4, 4))), 'mac' => null];
    }

    /** MAC `AA:BB:CC:DD:EE:FF` dari Hex-STRING 6 byte, hex polos 12 digit, atau bentuk berpemisah. */
    public static function mac(?string $raw): ?string
    {
        $clean = self::clean($raw);
        if ($clean === null) {
            return null;
        }

        if (preg_match('/^(?:[0-9A-Fa-f]{2}[\s:\-]){5}[0-9A-Fa-f]{2}$/', $clean) || preg_match('/^[0-9A-Fa-f]{12}$/', $clean)) {
            $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $clean) ?? '');

            return $hex === '000000000000' ? null : implode(':', str_split($hex, 2));
        }

        if (strlen($clean) === 6 && ! ctype_print($clean)) {
            return self::mac(bin2hex($clean));
        }

        return null;
    }

    /**
     * Nilai optik ÷100 (dBm). Rx/Tx ONU di luar (-60, 0) dibuang: 0 = tak ada bacaan, dan pernah
     * terbaca +1,49 dBm sesaat pada ONU ber-Rx −19 dBm (glitch tercatat di peta OID G02ID).
     */
    public static function centiDbm(?string $raw): ?float
    {
        $value = self::toInt($raw);
        if ($value === null) {
            return null;
        }

        $dbm = round($value / 100, 2);

        return ($dbm < 0.0 && $dbm > -60.0) ? $dbm : null;
    }

    /** Nilai ÷100 umum (suhu °C, tegangan V, arus mA, Tx SFP dBm). 0 = modul tak terpasang → null. */
    public static function centi(?string $raw): ?float
    {
        $value = self::toInt($raw);

        return $value === null || $value === 0 ? null : round($value / 100, 2);
    }

    /** Durasi centidetik → `3h 58m` / `2d 4h` untuk tampilan. */
    public static function duration(?int $centiseconds): ?string
    {
        if ($centiseconds === null || $centiseconds <= 0) {
            return null;
        }

        $seconds = intdiv($centiseconds, 100);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $days > 0 ? "{$days}d {$hours}h" : "{$hours}h {$minutes}m";
    }

    /**
     * Penyebab down terakhir HSGQ (`Power down`, `Laser out`) → phase ONU offline yang dipahami
     * AlarmEvaluator: daya putus = DyingGasp, cahaya hilang = LOS, lainnya Offline.
     */
    public static function offlinePhase(?string $lastDownCause): string
    {
        $cause = strtolower((string) $lastDownCause);

        return match (true) {
            str_contains($cause, 'power') || str_contains($cause, 'dying') => 'DyingGasp',
            str_contains($cause, 'laser') || str_contains($cause, 'los') || str_contains($cause, 'fiber') => 'LOS',
            default => 'Offline',
        };
    }

    /** Samarkan SN/MAC untuk laporan diagnostik: `HWTC****20`, `AA:BB:CC:xx:xx:xx`. */
    public static function mask(?string $identity): ?string
    {
        if ($identity === null || $identity === '') {
            return null;
        }

        if (preg_match('/^([0-9A-F]{2}:[0-9A-F]{2}:[0-9A-F]{2}):/i', $identity, $m)) {
            return $m[1].':xx:xx:xx';
        }

        return strlen($identity) <= 6
            ? str_repeat('*', strlen($identity))
            : substr($identity, 0, 4).str_repeat('*', strlen($identity) - 6).substr($identity, -2);
    }
}
