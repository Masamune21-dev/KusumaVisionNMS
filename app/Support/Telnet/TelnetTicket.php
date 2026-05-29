<?php

namespace App\Support\Telnet;

use Illuminate\Support\Facades\Crypt;

/**
 * Short-lived, tamper-proof ticket binding a user to a single OLT telnet session.
 * Encrypted with the app key so the proxy daemon can verify it without shared state.
 */
class TelnetTicket
{
    public static function issue(int $userId, int $oltId): string
    {
        $encrypted = Crypt::encryptString(json_encode([
            'u' => $userId,
            'o' => $oltId,
            'exp' => now()->addSeconds((int) config('telnet.ticket_ttl', 60))->timestamp,
        ]));

        // URL-safe so it survives query strings through nginx/browser untouched.
        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    /**
     * @return array{u:int, o:int}|null
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

        if (! is_array($data) || ! isset($data['u'], $data['o'], $data['exp'])) {
            return null;
        }

        if ((int) $data['exp'] < now()->timestamp) {
            return null;
        }

        return ['u' => (int) $data['u'], 'o' => (int) $data['o']];
    }
}
