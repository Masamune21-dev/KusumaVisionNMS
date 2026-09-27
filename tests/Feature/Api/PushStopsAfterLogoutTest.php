<?php

namespace Tests\Feature\Api;

use App\Models\FcmDeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Ponsel yang sudah logout tidak boleh lagi menerima push alarm.
 *
 * Dulu aplikasi mencabut sesinya dulu lalu memanggil `DELETE /devices` tanpa
 * token (selalu 401), jadi baris FCM tertinggal dan alarm terus terkirim.
 * Kini token push terkait sesi yang mendaftarkannya: logout — dari APK lama
 * sekalipun — ikut mencabutnya, dan sesi kedaluwarsa tak lagi dikirimi.
 */
class PushStopsAfterLogoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string, 2: PersonalAccessToken}
     */
    private function phoneSession(?User $user = null): array
    {
        $user ??= User::factory()->admin()->create();
        $token = $user->createToken('Xiaomi uji');

        return [$user, $token->plainTextToken, $token->accessToken];
    }

    /**
     * Guard Sanctum mengingat user dari request pertama dalam satu test; tanpa
     * reset, request berikutnya dengan token lain tetap memakai sesi pertama.
     */
    private function asPhone(string $bearer): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($bearer);
    }

    public function test_registration_is_linked_to_the_login_session(): void
    {
        [, $bearer, $session] = $this->phoneSession();

        $this->asPhone($bearer)->postJson('/api/v1/devices', ['token' => 'fcm-hp-1'])->assertOk();

        $this->assertDatabaseHas('fcm_device_tokens', ['token' => 'fcm-hp-1', 'personal_access_token_id' => $session->id]);
    }

    public function test_logout_from_an_old_apk_still_revokes_the_phone_push_token(): void
    {
        [$user, $bearer] = $this->phoneSession();
        [, $otherBearer] = $this->phoneSession($user);
        $this->asPhone($bearer)->postJson('/api/v1/devices', ['token' => 'fcm-hp-1'])->assertOk();
        $this->asPhone($otherBearer)->postJson('/api/v1/devices', ['token' => 'fcm-tablet'])->assertOk();

        // APK ≤1.8.4: logout tanpa fcm_token.
        $this->asPhone($bearer)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseMissing('fcm_device_tokens', ['token' => 'fcm-hp-1']);
        // Perangkat lain milik user yang sama tetap menerima.
        $this->assertDatabaseHas('fcm_device_tokens', ['token' => 'fcm-tablet']);
    }

    public function test_new_apk_logout_also_removes_a_legacy_unlinked_row(): void
    {
        [$user, $bearer] = $this->phoneSession();
        $stranger = User::factory()->create();
        FcmDeviceToken::create(['user_id' => $user->id, 'token' => 'fcm-lama']);
        FcmDeviceToken::create(['user_id' => $stranger->id, 'token' => 'fcm-orang-lain']);

        $this->asPhone($bearer)->postJson('/api/v1/auth/logout', ['fcm_token' => 'fcm-lama'])->assertOk();
        // Token milik user lain tak bisa dicabut lewat logout orang lain.
        [, $bearer2] = $this->phoneSession($user);
        $this->asPhone($bearer2)->postJson('/api/v1/auth/logout', ['fcm_token' => 'fcm-orang-lain'])->assertOk();

        $this->assertDatabaseMissing('fcm_device_tokens', ['token' => 'fcm-lama']);
        $this->assertDatabaseHas('fcm_device_tokens', ['token' => 'fcm-orang-lain']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_revoking_a_session_elsewhere_cascades_to_its_push_token(): void
    {
        [, $bearer, $session] = $this->phoneSession();
        $this->asPhone($bearer)->postJson('/api/v1/devices', ['token' => 'fcm-hp-1'])->assertOk();

        // Mis. sesi dicabut admin / dibuang sanctum:prune-expired.
        $session->delete();

        $this->assertDatabaseMissing('fcm_device_tokens', ['token' => 'fcm-hp-1']);
    }

    public function test_expired_sessions_are_not_delivered_but_legacy_rows_still_are(): void
    {
        config(['sanctum.expiration' => 60]);
        [$user, , $session] = $this->phoneSession();
        FcmDeviceToken::create(['user_id' => $user->id, 'personal_access_token_id' => $session->id, 'token' => 'fcm-sah']);
        FcmDeviceToken::create(['user_id' => $user->id, 'token' => 'fcm-lama-tanpa-kaitan']);

        $this->assertEqualsCanonicalizing(['fcm-sah', 'fcm-lama-tanpa-kaitan'], FcmDeviceToken::query()->deliverable()->pluck('token')->all());

        $session->forceFill(['created_at' => now()->subMinutes(61)])->save();
        $this->assertSame(['fcm-lama-tanpa-kaitan'], FcmDeviceToken::query()->deliverable()->pluck('token')->all());

        $session->forceFill(['created_at' => now(), 'expires_at' => now()->subMinute()])->save();
        $this->assertSame(['fcm-lama-tanpa-kaitan'], FcmDeviceToken::query()->deliverable()->pluck('token')->all());
    }
}
