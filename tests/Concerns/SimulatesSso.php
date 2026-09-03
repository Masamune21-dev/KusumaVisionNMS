<?php

namespace Tests\Concerns;

use App\Support\Pkce;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Alat bantu untuk menguji jalur /sso/callback tanpa IdP sungguhan.
 */
trait SimulatesSso
{
    protected string $ssoVerifier = 'verifier-yang-cukup-panjang-untuk-rfc7636-minimal-43-char';

    protected string $ssoState = 'state-uji';

    /**
     * Palsukan balasan /oauth/token milik IdP.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function fakeIdpClaims(array $claims = []): array
    {
        $payload = [
            'sub' => (string) Str::uuid(),
            'email' => 'orang@example.test',
            'name' => 'Orang Uji',
            'role' => 'operator',
            'apps' => ['nms'],
            'sid' => Str::random(48),
            'issued_at' => now()->timestamp,
            ...$claims,
        ];

        Http::fake([
            rtrim(config('sso.issuer'), '/').'/oauth/token' => Http::response($payload, 200),
        ]);

        return $payload;
    }

    /**
     * Session yang sudah berisi state + verifier, meniru langkah /sso/redirect.
     *
     * @return array<string, string>
     */
    protected function ssoRedirectSession(): array
    {
        return [
            'sso.state' => $this->ssoState,
            'sso.verifier' => $this->ssoVerifier,
        ];
    }

    protected function ssoCallbackUrl(?string $state = null, string $code = 'kode-otorisasi'): string
    {
        return '/sso/callback?'.http_build_query([
            'code' => $code,
            'state' => $state ?? $this->ssoState,
        ]);
    }

    /** Challenge untuk verifier di atas — dipakai saat memeriksa URL /authorize. */
    protected function ssoChallenge(): string
    {
        return Pkce::challengeFor($this->ssoVerifier);
    }
}
