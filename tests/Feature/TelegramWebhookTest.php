<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function configureBot(array $overrides = []): TelegramSetting
    {
        return TelegramSetting::create(array_merge([
            'enabled' => true,
            'bot_token' => '123:ABC',
            'chat_id' => '111',
            'webhook_secret' => 'super-secret-token',
            'commands_enabled' => true,
            'min_severity' => 'warning',
        ], $overrides));
    }

    private function update(string $chatId, string $text): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    private function postWebhook(array $payload, ?string $secret = 'super-secret-token')
    {
        $headers = $secret !== null ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : [];

        return $this->postJson(route('telegram.webhook'), $payload, $headers);
    }

    public function test_missing_or_wrong_secret_is_rejected(): void
    {
        Http::fake();
        $this->configureBot();

        $this->postWebhook($this->update('111', '/status'), secret: null)->assertForbidden();
        $this->postWebhook($this->update('111', '/status'), secret: 'wrong')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_disabled_commands_accepts_but_does_not_reply(): void
    {
        Http::fake();
        $this->configureBot(['commands_enabled' => false]);

        $this->postWebhook($this->update('111', '/status'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_unauthorized_chat_is_denied_without_leaking_data(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        SnmpOlt::create([
            'name' => 'OLT-RAHASIA', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.1',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => ['ok' => true, 'port_onus' => []],
        ]);

        $this->postWebhook($this->update('999', '/status'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '999'
            && str_contains($request['text'], 'Akses ditolak')
            && ! str_contains($request['text'], 'OLT-RAHASIA'));
    }

    public function test_id_and_ping_work_for_unregistered_chat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        $this->postWebhook($this->update('999', '/id'))->assertOk();
        $this->postWebhook($this->update('999', '/ping'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'], '999'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Pong'));
    }

    public function test_status_command_returns_summary_to_authorized_chat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        SnmpOlt::create([
            'name' => 'OLT-C320-PATI', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.7',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'ports' => [['if_index' => 1, 'slot' => 1, 'port' => 1, 'oper_status' => 'up']],
                'port_onus' => ['1_1' => ['count' => 1, 'onus' => [[
                    'slot' => 1, 'port' => 1, 'onu_id' => 5, 'online' => true,
                ]]]],
            ],
        ]);

        $this->postWebhook($this->update('111', '/status'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '111'
            && str_contains($request['text'], 'Status Jaringan')
            && str_contains($request['text'], 'ONU'));
    }

    public function test_onu_command_finds_cached_onu(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        SnmpOlt::create([
            'name' => 'OLT-C320-PATI', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.7',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'port_onus' => ['1_1' => ['onus' => [[
                    'slot' => 1, 'port' => 1, 'onu_id' => 5,
                    'interface' => 'gpon-onu_1/1/1:5',
                    'serial_number' => 'ZTEGC1234567',
                    'name' => 'Budi Santoso',
                    'online' => true, 'phase_state' => 'Working',
                    'rx_power_label' => '-21.300 dBm', 'rx_power_dbm' => -21.3,
                ]]]],
            ],
        ]);

        $this->postWebhook($this->update('111', '/onu ZTEGC1234567'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'ZTEGC1234567')
            && str_contains($request['text'], 'Online')
            && str_contains($request['text'], 'Budi Santoso'));
    }

    public function test_onu_command_reports_not_found(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        $this->postWebhook($this->update('111', '/onu NOPE000'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'], 'tidak ditemukan'));
    }

    public function test_non_message_update_is_ignored(): void
    {
        Http::fake();
        $this->configureBot();

        $this->postWebhook(['update_id' => 2, 'edited_message' => ['text' => 'hi']])->assertOk();

        Http::assertNothingSent();
    }

    public function test_register_webhook_route_calls_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);

        $admin = User::factory()->admin()->create();
        $this->configureBot(['webhook_secret' => null, 'commands_enabled' => false]);

        $this->actingAs($admin)
            ->post(route('settings.telegram.webhook.register'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:ABC/setWebhook')
            && isset($request['secret_token'])
            && $request['secret_token'] !== '');

        $setting = TelegramSetting::instance();
        $this->assertTrue($setting->commands_enabled);
        $this->assertNotEmpty($setting->webhook_secret);
    }
}
