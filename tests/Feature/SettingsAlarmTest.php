<?php

namespace Tests\Feature;

use App\Models\AlarmSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAlarmTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_alarm_setting_confirms_before_notify(): void
    {
        // Default aman = perilaku lama (debounce 2 poll) walau baris belum pernah disimpan.
        $this->assertTrue(AlarmSetting::instance()->confirm_before_notify);
        $this->assertTrue(AlarmSetting::confirmBeforeNotify());
    }

    public function test_admin_can_switch_to_realtime_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.alarm.update'), ['confirm_before_notify' => false])
            ->assertSessionHas('success');

        $this->assertFalse(AlarmSetting::instance()->confirm_before_notify);
        $this->assertFalse(AlarmSetting::confirmBeforeNotify());
    }

    public function test_admin_can_switch_back_to_confirm_mode(): void
    {
        AlarmSetting::create(['confirm_before_notify' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.alarm.update'), ['confirm_before_notify' => true])
            ->assertSessionHas('success');

        $this->assertTrue(AlarmSetting::instance()->confirm_before_notify);
    }

    public function test_non_admin_cannot_update_alarm_setting(): void
    {
        $operator = User::factory()->create(); // default operator

        $this->actingAs($operator)
            ->put(route('settings.alarm.update'), ['confirm_before_notify' => false])
            ->assertForbidden();
    }
}
