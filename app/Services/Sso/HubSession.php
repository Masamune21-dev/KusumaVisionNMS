<?php

namespace App\Services\Sso;

use Illuminate\Support\Facades\Redis;

/**
 * Pembaca sesi hub SSO (Redis DB 6, namespace bersama `kvsso:`).
 *
 * App ini tidak pernah menerbitkan sid — itu wewenang IdP. Yang dilakukan di sini
 * hanya memastikan sid masih hidup sekaligus memperpanjangnya, sehingga:
 *  - logout di app mana pun langsung terasa di app ini, dan
 *  - pengguna yang aktif di app ini tidak dianggap menganggur oleh IdP.
 */
class HubSession
{
    public static function ttl(): int
    {
        return (int) config('session.lifetime') * 60;
    }

    /**
     * Perpanjang sekaligus periksa keberadaan sid.
     *
     * EXPIRE mengembalikan 1 bila kunci ada (dan TTL-nya diperbarui) atau 0 bila
     * tidak — satu perintah menjawab kedua pertanyaan.
     *
     * Kunci metadata dan daftar app milik sid ini ikut disegarkan supaya halaman
     * "Perangkat Aktif" di IdP tidak kehilangan keterangan selagi sesinya justru
     * masih dipakai. Ketiganya dikirim dalam satu pipeline, jadi tetap satu
     * round-trip Redis per request.
     */
    public static function touch(string $sid): bool
    {
        $ttl = self::ttl();

        $results = Redis::connection('sso')->pipeline(function ($pipe) use ($sid, $ttl) {
            $pipe->expire('sid:'.$sid, $ttl);
            $pipe->expire('sid:'.$sid.':meta', $ttl);
            $pipe->expire('sid:'.$sid.':apps', $ttl);
        });

        return (bool) ($results[0] ?? false);
    }
}
