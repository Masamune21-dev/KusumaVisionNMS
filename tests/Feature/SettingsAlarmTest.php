<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\AlarmSetting;
use App\Models\FcmSetting;
use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAlarmTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payload lengkap tab Alarm (semua field wajib ikut, seperti form aslinya).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'confirm_before_notify' => true,
            'min_severity' => AlarmEvent::SEVERITY_WARNING,
            'notify_on_raise' => true,
            'notify_on_clear' => false,
            'suppress_child_alarms' => true,
            'group_odp_alarms' => true,
        ], $overrides);
    }

    public function test_default_alarm_setting_confirms_before_notify(): void
    {
        // Default aman = perilaku lama (debounce 2 poll) walau baris belum pernah disimpan.
        $this->assertTrue(AlarmSetting::instance()->confirm_before_notify);
        $this->assertTrue(AlarmSetting::confirmBeforeNotify());
    }

    public function test_default_policy_correlates_root_cause(): void
    {
        // Out of the box: alarm anak (ONU) disupres saat induknya down & grup ODP aktif.
        $this->assertTrue(AlarmSetting::suppressChildAlarms());
        $this->assertTrue(AlarmSetting::groupOdpAlarms());
        $this->assertSame(AlarmEvent::types(), AlarmSetting::instance()->notifyTypes());
    }

    public function test_admin_can_switch_to_realtime_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.alarm.update'), $this->payload(['confirm_before_notify' => false]))
            ->assertSessionHas('success');

        $this->assertFalse(AlarmSetting::instance()->confirm_before_notify);
        $this->assertFalse(AlarmSetting::confirmBeforeNotify());
    }

    public function test_admin_can_switch_back_to_confirm_mode(): void
    {
        AlarmSetting::create(['confirm_before_notify' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.alarm.update'), $this->payload(['confirm_before_notify' => true]))
            ->assertSessionHas('success');

        $this->assertTrue(AlarmSetting::instance()->confirm_before_notify);
    }

    public function test_admin_can_save_central_notification_policy(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.alarm.update'), $this->payload([
                'min_severity' => AlarmEvent::SEVERITY_MAJOR,
                'notify_on_clear' => true,
                'notify_types' => [AlarmEvent::TYPE_ODP_DOWN, AlarmEvent::TYPE_LOS],
                'suppress_child_alarms' => false,
                'group_odp_alarms' => false,
            ]))
            ->assertSessionHas('success');

        $setting = AlarmSetting::instance();

        $this->assertSame(AlarmEvent::SEVERITY_MAJOR, $setting->min_severity);
        $this->assertTrue($setting->notifyOnClear());
        // Disimpan ternormalisasi ke urutan kanonik AlarmEvent::types().
        $this->assertSame([AlarmEvent::TYPE_ODP_DOWN, AlarmEvent::TYPE_LOS], $setting->notifyTypes());
        $this->assertFalse(AlarmSetting::suppressChildAlarms());
        $this->assertFalse(AlarmSetting::groupOdpAlarms());
    }

    public function test_central_policy_drives_both_telegram_and_mobile_channels(): void
    {
        // Inti pemusatan: satu baris kebijakan, dua kanal. Kolom lama di tabel kanal diabaikan.
        TelegramSetting::create([
            'enabled' => true,
            'bot_token' => '123:ABC',
            'chat_id' => '111',
            'min_severity' => AlarmEvent::SEVERITY_WARNING,
            'notify_on_raise' => true,
            'notify_on_clear' => true,
            'notify_types' => [AlarmEvent::TYPE_ONU_OFFLINE],
        ]);
        FcmSetting::create([
            'enabled' => true,
            'min_severity' => AlarmEvent::SEVERITY_CRITICAL,
            'notify_on_raise' => false,
            'notify_on_clear' => false,
            'notify_types' => [AlarmEvent::TYPE_HIGH_RX],
        ]);

        AlarmSetting::create([
            'min_severity' => AlarmEvent::SEVERITY_MAJOR,
            'notify_on_raise' => true,
            'notify_on_clear' => false,
            'notify_types' => [AlarmEvent::TYPE_PORT_DOWN, AlarmEvent::TYPE_ODP_DOWN],
        ]);

        foreach ([TelegramSetting::instance(), FcmSetting::instance()] as $channel) {
            $this->assertSame(AlarmEvent::SEVERITY_RANK[AlarmEvent::SEVERITY_MAJOR], $channel->minSeverityRank());
            $this->assertTrue($channel->notifyOnRaise());
            $this->assertFalse($channel->notifyOnClear());
            $this->assertTrue($channel->shouldNotifyType(AlarmEvent::TYPE_ODP_DOWN));
            $this->assertFalse($channel->shouldNotifyType(AlarmEvent::TYPE_ONU_OFFLINE));
        }
    }

    public function test_non_admin_cannot_update_alarm_setting(): void
    {
        $operator = User::factory()->create(); // default operator

        $this->actingAs($operator)
            ->put(route('settings.alarm.update'), $this->payload(['confirm_before_notify' => false]))
            ->assertForbidden();
    }
}
