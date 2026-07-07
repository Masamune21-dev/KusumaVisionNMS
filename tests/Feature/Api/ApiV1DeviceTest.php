<?php

namespace Tests\Feature\Api;

use App\Models\FcmDeviceToken;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Fcm\FcmAlarmNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1DeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_device_token_requires_auth(): void
    {
        $this->postJson('/api/v1/devices', ['token' => 'abc'])->assertStatus(401);
    }

    public function test_register_and_rebind_device_token(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/devices', [
            'token' => 'tok-123', 'device_name' => 'Pixel',
        ])->assertOk()->assertJsonPath('data.registered', true);

        $this->assertDatabaseHas('fcm_device_tokens', ['token' => 'tok-123', 'user_id' => $a->id]);

        // Perangkat sama dipakai user lain → token di-rebind, tidak menggandakan baris.
        $this->actingAs($b, 'sanctum')->postJson('/api/v1/devices', ['token' => 'tok-123'])->assertOk();

        $this->assertSame(1, FcmDeviceToken::query()->where('token', 'tok-123')->count());
        $this->assertDatabaseHas('fcm_device_tokens', ['token' => 'tok-123', 'user_id' => $b->id]);
    }

    public function test_delete_device_token(): void
    {
        $user = User::factory()->create();
        FcmDeviceToken::create(['user_id' => $user->id, 'token' => 'tok-x']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/devices', ['token' => 'tok-x'])
            ->assertOk();

        $this->assertDatabaseMissing('fcm_device_tokens', ['token' => 'tok-x']);
    }

    public function test_notifier_is_noop_without_credentials(): void
    {
        // Tanpa service-account JSON, FCM harus mati total (polling tak boleh gagal).
        config(['services.fcm.credentials' => '/path/tidak/ada.json']);
        $user = User::factory()->create();
        FcmDeviceToken::create(['user_id' => $user->id, 'token' => 'tok-y']);

        $notifier = app(FcmAlarmNotifier::class);
        $this->assertFalse($notifier->enabled());

        // Tidak melempar exception walau ada token & "alarm".
        $olt = SnmpOlt::create([
            'name' => 'OLT-X', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.9',
            'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
        ]);
        $notifier->notify($olt, [], []);
        $this->assertTrue(true);
    }
}
