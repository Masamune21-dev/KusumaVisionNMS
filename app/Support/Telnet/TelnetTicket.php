<?php

namespace App\Support\Telnet;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Short-lived, tamper-proof ticket binding a user to a single OLT telnet session.
 * Encrypted with the app key so the proxy daemon can verify it without shared state.
 *
 * Sekali pakai: setiap tiket membawa `jti` acak yang dicatat di cache
 * bersama (Redis) selama TTL; {@see consume()} menghanguskannya saat WebSocket
 * tersambung sehingga tiket yang bocor lewat access log tidak bisa dipakai ulang.
 */
class TelnetTicket
{
    private const CACHE_PREFIX = 'telnet_ticket:';

    public static function ttl(): int
    {
        return max(5, (int) config('telnet.ticket_ttl', 30));
    }

    public static function issue(int $userId, int $oltId): string
    {
        $jti = Str::random(32);
        $ttl = self::ttl();

        Cache::put(self::CACHE_PREFIX.$jti, ['u' => $userId, 'o' => $oltId], $ttl);

        $encrypted = Crypt::encryptString(json_encode([
            'u' => $userId,
            'o' => $oltId,
            'j' => $jti,
            'exp' => now()->addSeconds($ttl)->timestamp,
        ]));

        // URL-safe so it survives query strings through nginx/browser untouched.
        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    /**
     * Verifikasi tanpa menghanguskan (tiket masih bisa dipakai setelah ini).
     *
     * @return array{u:int, o:int, j:string}|null
     */
    public static function verify(string $token): ?array
    {
        $encrypted = strtr($token, '-_', '+/');
        $encrypted .= str_repeat('=', (4 - strlen($encrypted) % 4) % 4);

        try {
            $data = json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || ! isset($data['u'], $data['o'], $data['j'], $data['exp'])) {
            return null;
        }

        if ((int) $data['exp'] < now()->timestamp) {
            return null;
        }

        $stored = Cache::get(self::CACHE_PREFIX.$data['j']);
        if (! is_array($stored) || (int) ($stored['u'] ?? -1) !== (int) $data['u'] || (int) ($stored['o'] ?? -1) !== (int) $data['o']) {
            return null;
        }

        return ['u' => (int) $data['u'], 'o' => (int) $data['o'], 'j' => (string) $data['j']];
    }

    /**
     * Verifikasi DAN hanguskan: tiket hanya sah untuk satu koneksi.
     *
     * @return array{u:int, o:int, j:string}|null
     */
    public static function consume(string $token): ?array
    {
        $ticket = self::verify($token);
        if ($ticket === null) {
            return null;
        }

        Cache::forget(self::CACHE_PREFIX.$ticket['j']);

        return $ticket;
    }
}
