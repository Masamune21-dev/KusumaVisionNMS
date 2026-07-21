<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Waktu tampilan (presentation layer) — penyimpanan tetap UTC.
 *
 * Label zona ("WIB", "AST") diturunkan otomatis dari `app.display_timezone`, jadi
 * deployment cukup menyetel SATU knob (APP_DISPLAY_TIMEZONE). Env
 * APP_DISPLAY_TIMEZONE_LABEL tinggal override opsional untuk zona yang singkatan
 * tzdb-nya tak ramah (mis. "+07").
 */
class DisplayTime
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Jakarta');
    }

    public static function label(): string
    {
        $override = (string) config('app.display_timezone_label', '');

        return $override !== '' ? $override : Carbon::now(self::timezone())->format('T');
    }

    /**
     * Waktu kini di zona tampilan + label, mis. "21 Jul 2026 14:05 WIB".
     * Dipakai laporan (PDF/CSV) & pesan Telegram.
     */
    public static function stamp(string $format = 'd M Y H:i', bool $translated = true): string
    {
        $now = Carbon::now()->timezone(self::timezone());

        return ($translated ? $now->translatedFormat($format) : $now->format($format)).' '.self::label();
    }
}
