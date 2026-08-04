<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\AlarmSetting;
use App\Models\FcmSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsFcmTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_fcm_setting_is_enabled_and_follows_central_policy(): void
    {
        $s = FcmSetting::instance();

        $this->assertTrue($s->enabled);
        // Filter alarm kini terpusat di AlarmSetting (default: kirim saat naik, semua jenis).
        $this->assertTrue($s->notifyOnRaise());
        $this->assertFalse($s->notifyOnClear());
        $this->assertSame(AlarmEvent::types(), $s->notifyTypes());
    }

    public function test_admin_can_toggle_mobile_push_channel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.fcm.update'), ['enabled' => false])
            ->assertSessionHas('success');

        $this->assertFalse(FcmSetting::instance()->enabled);
    }

    public function test_fcm_endpoint_no_longer_owns_alarm_filters(): void
    {
        // Filter dikirim ke endpoint kanal (mis. dari klien lama) tak boleh mengubah kebijakan:
        // satu-satunya pintu adalah Settings → Alarm.
        AlarmSetting::create(['min_severity' => AlarmEvent::SEVERITY_WARNING]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.fcm.update'), [
                'enabled' => true,
                'min_severity' => AlarmEvent::SEVERITY_CRITICAL,
                'notify_types' => [AlarmEvent::TYPE_LOS],
            ])
            ->assertSessionHas('success');

        $this->assertSame(AlarmEvent::SEVERITY_WARNING, AlarmSetting::instance()->min_severity);
        $this->assertSame(
            AlarmEvent::SEVERITY_RANK[AlarmEvent::SEVERITY_WARNING],
            FcmSetting::instance()->minSeverityRank(),
        );
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $operator = User::factory()->create(); // default operator

        $this->actingAs($operator)
            ->put(route('settings.fcm.update'), ['enabled' => true])
            ->assertForbidden();
    }

    public function test_manual_send_without_devices_reports_error(): void
    {
        // Kredensial FCM ada (file dummy) tapi belum ada perangkat → pesan error.
        $path = storage_path('app/testing-fcm.json');
        @file_put_contents($path, '{}');
        config(['services.fcm.credentials' => $path]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('settings.fcm.send'), ['title' => 'Halo', 'body' => 'Pesan uji'])
            ->assertSessionHas('error');

        @unlink($path);
    }

    public function test_manual_send_requires_title_and_body(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('settings.fcm.send'), ['title' => '', 'body' => ''])
            ->assertSessionHasErrors(['title', 'body']);
    }
}
