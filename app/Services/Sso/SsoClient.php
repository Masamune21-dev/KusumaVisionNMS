<?php

namespace App\Services\Sso;

use App\Support\Pkce;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien Authorization Code Flow + PKCE ke IdP KusumaVision.
 */
class SsoClient
{
    /**
     * URL /authorize tempat browser pengguna diarahkan.
     */
    public function authorizeUrl(string $state, string $verifier): string
    {
        return config('sso.issuer').'/authorize?'.http_build_query([
            'client_id' => config('sso.client_id'),
            'redirect_uri' => config('sso.redirect_uri'),
            'state' => $state,
            'code_challenge' => Pkce::challengeFor($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Tukar authorization code menjadi klaim identitas.
     *
     * Panggilan ini server-ke-server: `client_secret` tidak pernah melewati
     * browser, dan `code_verifier` membuktikan bahwa app inilah yang tadi memulai
     * permintaannya.
     *
     * @return array{sub:string, email:string, name:string, role:string, apps:array<int,string>, sid:string}
     *
     * @throws RuntimeException
     */
    public function exchange(string $code, string $verifier): array
    {
        $response = Http::asForm()
            ->timeout((int) config('sso.timeout'))
            ->acceptJson()
            ->post(config('sso.issuer').'/oauth/token', [
                'client_id' => config('sso.client_id'),
                'client_secret' => config('sso.client_secret'),
                'code' => $code,
                'code_verifier' => $verifier,
                'redirect_uri' => config('sso.redirect_uri'),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Penukaran kode SSO gagal: '.($response->json('error_description') ?? $response->status())
            );
        }

        $claims = $response->json();

        foreach (['sub', 'email', 'name', 'role', 'apps', 'sid'] as $field) {
            if (! isset($claims[$field])) {
                throw new RuntimeException("Balasan IdP tidak lengkap: [{$field}] tidak ada.");
            }
        }

        return $claims;
    }

    /**
     * URL logout tunggal di IdP.
     */
    public function logoutUrl(): string
    {
        return config('sso.issuer').'/logout?'.http_build_query([
            'client_id' => config('sso.client_id'),
        ]);
    }
}
