<?php

namespace App\Support;

/**
 * Terjemahan untuk teks yang dibuat sistem (alarm, Telegram, push FCM, hasil tugas antrean).
 *
 * Teks ini disimpan ke DB atau dikirim ke banyak penerima sekaligus, jadi bahasanya
 * mengikuti bahasa instalasi (`APP_LOCALE`, dipilih saat install) — bukan bahasa UI
 * user yang kebetulan sedang memicu aksinya. Teks yang tampil langsung di layar
 * pemakai tetap memakai __() biasa (bahasa per user).
 */
class SystemText
{
    public static function locale(): string
    {
        return Locale::normalize((string) config('app.system_locale', config('app.fallback_locale')));
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        return (string) __($key, $replace, self::locale());
    }
}
