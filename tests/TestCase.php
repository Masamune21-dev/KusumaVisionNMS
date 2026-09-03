<?php

namespace Tests;

use App\Services\Sso\HubSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Selain login seperti biasa, terbitkan juga sesi hub SSO-nya.
     *
     * Sejak autentikasi dipindah ke sso.kusumavision.net, sesi yang sah selalu
     * punya `sso.sid`, dan {@see \App\Http\Middleware\EnsureSsoSessionAlive}
     * menendang sesi yang tidak punya. Tanpa penyesuaian ini setiap test yang
     * memakai actingAs() akan menerima redirect ke halaman login, bukan halaman
     * yang sedang diuji.
     *
     * Kunci Redis ditulis langsung, bukan lewat HubSession::start(), karena
     * penerbitan sid adalah wewenang IdP — app ini memang tidak punya metode itu.
     * Sengaja memakai Redis sungguhan (DB uji, lihat REDIS_SSO_DB di phpunit.xml)
     * supaya test tetap melewati jalur middleware yang sama persis dengan produksi.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        $sid = Str::random(48);
        Redis::connection('sso')->setex('sid:'.$sid, HubSession::ttl(), (string) $user->getAuthIdentifier());

        $this->withSession(['sso.sid' => $sid]);

        return $this;
    }
}
