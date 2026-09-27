<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Preferensi tema antarmuka — satu tempat yang menentukan nilai sah,
 * urutan resolusi, dan nama cookie-nya.
 *
 * Ada DUA nilai yang berbeda dan sengaja tidak disatukan:
 *
 *   preference() — apa yang DIPILIH pengguna: dark | light | system
 *   document()   — apa yang HARUS DIPASANG di <html> saat merender: dark | light
 *
 * Server tidak bisa tahu setelan OS pengguna, jadi 'system' diresolusi di
 * browser oleh skrip kecil di <head>. Yang dikirim server tetap nilai konkret
 * supaya halaman tetap bertema benar bila JavaScript mati — dan supaya tidak
 * pernah ada momen <html data-theme="system"> yang tidak cocok dengan blok
 * CSS mana pun.
 *
 * Sumber kebenarannya kolom users.theme. Cookie dipakai karena localStorage
 * TIDAK ikut dalam permintaan dokumen — tanpa cookie, render pertama server
 * mustahil tahu tema pilihan dan pengguna akan melihat kedipan tiap kali
 * membuka halaman dari awal.
 */
class Theme
{
    public const DARK = 'dark';

    public const LIGHT = 'light';

    public const SYSTEM = 'system';

    /** Dipakai saat pengguna belum pernah memilih apa pun. */
    public const DEFAULT = self::DARK;

    public const COOKIE = 'kv_theme';

    /** Satu tahun; preferensi tampilan tidak perlu ditanyakan ulang tiap bulan. */
    public const COOKIE_MINUTES = 60 * 24 * 365;

    /** @return list<string> */
    public static function options(): array
    {
        return [self::DARK, self::LIGHT, self::SYSTEM];
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::options(), true);
    }

    /**
     * Pilihan pengguna: users.theme -> cookie -> bawaan.
     *
     * Kolom didahulukan supaya pilihan ikut berpindah perangkat; cookie
     * menutup dua kasus yang tidak punya baris pengguna: halaman publik
     * (Welcome, login) dan permintaan sebelum sesi terbentuk.
     */
    public static function preference(Request $request): string
    {
        $stored = $request->user()?->theme;

        if (self::isValid($stored)) {
            return $stored;
        }

        $cookie = $request->cookie(self::COOKIE);

        return self::isValid(is_string($cookie) ? $cookie : null) ? $cookie : self::DEFAULT;
    }

    /**
     * Nilai konkret untuk atribut data-theme di <html>.
     *
     * 'system' dipetakan ke bawaan di sini; skrip di <head> yang nanti
     * membetulkannya ke hasil prefers-color-scheme sebelum satu piksel pun
     * tergambar.
     */
    public static function document(Request $request): string
    {
        $preference = self::preference($request);

        return $preference === self::SYSTEM ? self::DEFAULT : $preference;
    }
}
