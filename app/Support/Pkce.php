<?php

namespace App\Support;

/**
 * PKCE (RFC 7636) — pengikat authorization code ke sesi browser yang memulainya.
 *
 * `code_verifier` tidak pernah meninggalkan session app ini; yang dikirim lewat
 * URL hanyalah hash-nya. Akibatnya code yang tersadap dari log atau header
 * Referer tidak bisa ditukar oleh siapa pun selain pemilik session aslinya.
 */
final class Pkce
{
    /**
     * BASE64URL(SHA256(verifier)), tanpa padding — metode S256.
     */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
