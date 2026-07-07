<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\FcmSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsFcmTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_fcm_setting_is_enabled_raise_major(): void
    {
        $s = FcmSetting::instance();

        $this->assertTrue($s->enabled);
        $this->assertTrue($s->notify_on_raise);
        $this->assertFalse($s->notify_on_clear);
        $this->assertSame(AlarmEvent::SEVERITY_MAJOR, $s->min_severity);
        // null notify_types → semua tipe.
        $this->assertSame(AlarmEvent::types(), $s->notifyTypes());
    }

    public function test_admin_can_update_fcm_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.fcm.update'), [
                'enabled' => true,
                'min_severity' => AlarmEvent::SEVERITY_CRITICAL,
                'notify_on_raise' => true,
                'notify_on_clear' => true,
                'notify_types' => [AlarmEvent::TYPE_LOS, AlarmEvent::TYPE_DYING_GASP],
            ])
            ->assertSessionHas('success');

        $s = FcmSetting::instance();
        $this->assertSame(AlarmEvent::SEVERITY_CRITICAL, $s->min_severity);
        $this->assertTrue($s->notify_on_clear);
        $this->assertSame([AlarmEvent::TYPE_LOS, AlarmEvent::TYPE_DYING_GASP], $s->notifyTypes());
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $operator = User::factory()->create(); // default operator

        $this->actingAs($operator)
            ->put(route('settings.fcm.update'), ['min_severity' => AlarmEvent::SEVERITY_MAJOR])
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
