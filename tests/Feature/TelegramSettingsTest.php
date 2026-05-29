<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\AlarmEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_settings_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('settings.edit'))->assertOk();
    }

    public function test_operator_cannot_view_settings_page(): void
    {
        $operator = User::factory()->create(); // default operator

        $this->actingAs($operator)->get(route('settings.edit'))->assertForbidden();
    }

    public function test_admin_can_save_telegram_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.telegram.update'), [
                'enabled' => true,
                'bot_token' => '123:ABC',
                'chat_id' => '111, -1002',
                'min_severity' => 'major',
                'notify_on_raise' => true,
                'notify_on_clear' => false,
            ])
            ->assertSessionHas('success');

        $setting = TelegramSetting::instance();
        $this->assertTrue($setting->enabled);
        $this->assertSame('123:ABC', $setting->bot_token);
        $this->assertSame('major', $setting->min_severity);
        $this->assertSame(['111', '-1002'], $setting->chatIds());
    }

    public function test_blank_token_preserves_existing_token(): void
    {
        $admin = User::factory()->admin()->create();
        TelegramSetting::create([
            'enabled' => true,
            'bot_token' => 'KEEP:ME',
            'chat_id' => '111',
            'min_severity' => 'warning',
        ]);

        $this->actingAs($admin)
            ->put(route('settings.telegram.update'), [
                'enabled' => true,
                'bot_token' => '',
                'chat_id' => '222',
                'min_severity' => 'warning',
                'notify_on_raise' => true,
                'notify_on_clear' => false,
            ])
            ->assertSessionHas('success');

        $this->assertSame('KEEP:ME', TelegramSetting::instance()->bot_token);
        $this->assertSame('222', TelegramSetting::instance()->chat_id);
    }

    public function test_test_endpoint_sends_telegram_message(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = User::factory()->admin()->create();
        TelegramSetting::create([
            'enabled' => true,
            'bot_token' => '123:ABC',
            'chat_id' => '111',
            'min_severity' => 'warning',
        ]);

        $this->actingAs($admin)
            ->post(route('settings.telegram.test'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:ABC/sendMessage')
            && $request['chat_id'] === '111');
    }

    public function test_raised_alarm_dispatches_telegram_when_enabled(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        TelegramSetting::create([
            'enabled' => true,
            'bot_token' => '123:ABC',
            'chat_id' => '-1009',
            'min_severity' => 'warning',
            'notify_on_raise' => true,
        ]);

        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.7',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'ports' => [
                    ['if_index' => 1, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up'],
                ],
                'port_onus' => [
                    '1_1' => ['onus' => [[
                        'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
                        'serial_number' => 'ZTEGAAAA0009', 'admin_state' => 'active',
                        'phase_state' => 'LOS', 'online' => false, 'last_down_cause' => 'LOS',
                    ]]],
                ],
            ],
        ]);

        $previousOnline = [
            'ok' => true,
            'ports' => [['if_index' => 1, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up']],
            'port_onus' => [
                '1_1' => ['onus' => [[
                    'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
                    'serial_number' => 'ZTEGAAAA0009', 'admin_state' => 'active',
                    'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'Normal',
                ]]],
            ],
        ];

        (new AlarmEvaluator)->evaluate($olt, $previousOnline);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'LOS'));
    }

    public function test_no_telegram_sent_when_disabled(): void
    {
        Http::fake();

        TelegramSetting::create([
            'enabled' => false,
            'bot_token' => '123:ABC',
            'chat_id' => '111',
            'min_severity' => 'warning',
        ]);

        $olt = SnmpOlt::create([
            'name' => 'X', 'vendor' => 'ZTE C320', 'ip' => '10.30.0.8',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'ports' => [['if_index' => 1, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'down']],
                'port_onus' => [],
            ],
        ]);

        $previousUp = [
            'ok' => true,
            'ports' => [['if_index' => 1, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up']],
            'port_onus' => [],
        ];

        (new AlarmEvaluator)->evaluate($olt, $previousUp);

        Http::assertNothingSent();
    }

    public function test_large_alarm_batch_is_split_into_multiple_messages(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        TelegramSetting::create([
            'enabled' => true,
            'bot_token' => '123:ABC',
            'chat_id' => '111',
            'min_severity' => 'warning',
            'notify_on_raise' => true,
        ]);

        $onus = fn (bool $online) => collect(range(1, 12))->map(fn (int $i) => [
            'slot' => 1, 'port' => 1, 'onu_id' => $i, 'interface' => "gpon-onu_1/1/1:{$i}",
            'serial_number' => sprintf('ZTEG%08d', $i), 'admin_state' => 'active',
            'phase_state' => $online ? 'Working' : 'Offline', 'online' => $online,
            'last_down_cause' => $online ? 'Normal' : 'LOSi',
        ])->all();
        $snap = fn (bool $online) => [
            'ok' => true,
            'ports' => [['if_index' => 1, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up']],
            'port_onus' => ['1_1' => ['onus' => $onus($online)]],
        ];

        $olt = SnmpOlt::create([
            'name' => 'BULK', 'vendor' => 'ZTE C320', 'ip' => '10.30.0.20',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => $snap(false),
        ]);

        // 12 ONUs go online -> offline in one cycle: 12 alarms split into 10 + 2 = 2 messages.
        (new AlarmEvaluator)->evaluate($olt, $snap(true));

        Http::assertSentCount(2);
    }
}
