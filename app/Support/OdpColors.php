<?php

namespace App\Support;

use App\Models\Odp;

/**
 * Palet warna pin ODP di peta — satu-satunya sumber kebenaran (dipakai validasi backend,
 * dikirim ke frontend web sebagai prop Inertia, dan ke aplikasi Android lewat meta API),
 * supaya daftar warnanya tidak terduplikasi di PHP/JS/Dart.
 *
 * Palet sengaja MENGHINDARI hijau & merah karena keduanya sudah dipakai pin ONU untuk
 * status online/offline — pin ODP harus tetap terbaca sebagai "bukan status".
 */
class OdpColors
{
    /** Warna bawaan pin ODP (dipakai bila kolom `odps.color` null). */
    public const DEFAULT = '#f59e0b';

    /** @var array<int, string> */
    public const PALETTE = [
        '#f59e0b', // amber (default)
        '#fb923c', // oranye
        '#facc15', // kuning
        '#a16207', // amber tua
        '#14b8a6', // teal
        '#06b6d4', // cyan tua
        '#22d3ee', // cyan
        '#38bdf8', // biru langit
        '#3b82f6', // biru
        '#6366f1', // indigo
        '#8b5cf6', // ungu
        '#c084fc', // lavender
        '#d946ef', // fuchsia
        '#ec4899', // merah muda
        '#94a3b8', // abu
        '#e2e8f0', // putih-abu
    ];

    /**
     * Aturan validasi payload ganti warna — dipakai identik oleh rute web & REST API v1.
     * `color` null/kosong = reset ke default; `random: true` mengabaikan `color`.
     *
     * @var array<string, array<int, string>>
     */
    public const RULES = [
        'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        'random' => ['nullable', 'boolean'],
        'apply_to_port' => ['nullable', 'boolean'],
    ];

    public static function isValid(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', trim($hex));
    }

    /**
     * Bentuk simpan baku: lowercase "#rrggbb". String kosong/invalid → null (= pakai default).
     */
    public static function normalize(?string $hex): ?string
    {
        $hex = trim((string) $hex);

        return $hex !== '' && self::isValid($hex) ? mb_strtolower($hex) : null;
    }

    /**
     * Warna acak untuk sebuah PON port: diambil dari palet, dan sebisa mungkin BUKAN warna
     * yang sudah dipakai port lain di OLT yang sama — supaya antar-port mudah dibedakan
     * di peta. Kalau palet sudah habis terpakai, ambil warna yang paling jarang dipakai.
     */
    public static function randomFor(int $oltId, ?int $slot = null, ?int $port = null): string
    {
        $used = Odp::query()
            ->where('snmp_olt_id', $oltId)
            ->whereNotNull('color')
            // Port yang sedang diwarnai tak dihitung — warnanya memang akan ditimpa.
            ->when(
                $slot !== null && $port !== null,
                fn ($query) => $query->whereNot(fn ($group) => $group->where('slot', $slot)->where('port', $port)),
            )
            ->pluck('color')
            ->map(fn (?string $color) => mb_strtolower((string) $color))
            ->all();

        $unused = array_values(array_diff(self::PALETTE, $used));
        if ($unused !== []) {
            return $unused[array_rand($unused)];
        }

        $counts = array_count_values($used);
        $least = self::PALETTE;
        usort($least, fn (string $a, string $b) => ($counts[$a] ?? 0) <=> ($counts[$b] ?? 0));

        return $least[0];
    }
}
