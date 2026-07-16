<?php

namespace App\Support;

use App\Http\Controllers\LocaleController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;

/**
 * Sumber tunggal daftar bahasa UI yang didukung aplikasi.
 * Dipakai middleware {@see SetLocale},
 * {@see LocaleController}, dan dibagikan ke frontend
 * lewat {@see HandleInertiaRequests}.
 */
class Locale
{
    /**
     * Kode locale yang didukung → label native untuk switcher.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];

    public const DEFAULT = 'id';

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::SUPPORTED);
    }

    /**
     * Normalkan input apa pun ke locale valid (fallback ke default aplikasi).
     */
    public static function normalize(?string $code): string
    {
        if (self::isSupported($code)) {
            return $code;
        }

        $fallback = (string) config('app.locale', self::DEFAULT);

        return self::isSupported($fallback) ? $fallback : self::DEFAULT;
    }

    /**
     * Daftar locale untuk dibagikan ke frontend: [{code, label}].
     *
     * @return array<int, array{code:string, label:string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $code, string $label) => ['code' => $code, 'label' => $label],
            array_keys(self::SUPPORTED),
            array_values(self::SUPPORTED),
        );
    }
}
