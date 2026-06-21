<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\CData\CDataOltScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    private function callbackUpdate(string $chatId, string $data, int $messageId = 50): array
    {
        return [
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => $data,
                'message' => [
                    'message_id' => $messageId,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
            ],
        ];
    }

    private function postWebhook(array $payload, ?string $secret = 'super-secret-token')
    {
        $headers = $secret !== null ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : [];

        return $this->postJson(route('telegram.webhook'), $payload, $headers);
    }

    private function seedOltWithOnus(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-C320-PATI', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.7',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'count' => 3, 'onus' => [
                    [
                        'slot' => 1, 'port' => 1, 'onu_id' => 1, 'interface' => 'gpon-onu_1/1/1:1',
                        'serial_number' => 'ZTEGOK000001', 'name' => 'Budi', 'online' => true,
                        'phase_state' => 'Working', 'rx_power_dbm' => -21.3, 'rx_power_label' => '-21.300 dBm',
                    ],
                    [
                        'slot' => 1, 'port' => 1, 'onu_id' => 2, 'interface' => 'gpon-onu_1/1/1:2',
                        'serial_number' => 'ZTEGLOS00002', 'name' => 'Andi', 'online' => false,
                        'phase_state' => 'LOS', 'last_down_cause' => 'LOS', 'rx_power_dbm' => null,
                    ],
                    [
                        'slot' => 1, 'port' => 1, 'onu_id' => 3, 'interface' => 'gpon-onu_1/1/1:3',
                        'serial_number' => 'ZTEGRX000003', 'name' => 'Citra', 'online' => true,
                        'phase_state' => 'Working', 'rx_power_dbm' => -29.4, 'rx_power_label' => '-29.400 dBm',
                    ],
                ]]],
            ],
        ]);
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

    public function test_help_lists_commands_and_is_public(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        // /help publik — chat tak terdaftar (999) tetap dilayani.
        $this->postWebhook($this->update('999', '/help'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'KusumaVision NMS')
            && str_contains($request['text'], '/refresh')
            && str_contains($request['text'], '/search')
            && str_contains($request['text'], '/status')
            && isset($request['reply_markup']['inline_keyboard']));
    }

    public function test_refresh_command_scans_cdata_olts_and_reports(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        SnmpOlt::create([
            'name' => 'CDATA-EPON-1', 'vendor' => 'C-Data EPON 17409', 'ip' => '10.0.0.50',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
        ]);

        // Scanner palsu: tidak menyentuh jaringan, melaporkan jumlah ONU tetap.
        $this->app->instance(CDataOltScanner::class, new class extends CDataOltScanner
        {
            public function __construct() {}

            public function scan(SnmpOlt $olt): int
            {
                return 258;
            }
        });

        $this->postWebhook($this->update('111', '/refresh'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'Refresh OLT C-Data')
            && str_contains($request['text'], 'CDATA-EPON-1')
            && str_contains($request['text'], '258 ONU'));
    }

    public function test_refresh_command_ignores_zte_olts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);
        $this->seedOltWithOnus(); // ZTE saja

        // Scanner palsu yang menggagalkan test bila (keliru) dipanggil untuk OLT ZTE.
        $this->app->instance(CDataOltScanner::class, new class extends CDataOltScanner
        {
            public function __construct() {}

            public function scan(SnmpOlt $olt): int
            {
                throw new \RuntimeException('OLT ZTE tidak boleh di-scan oleh /refresh C-Data');
            }
        });

        $this->postWebhook($this->update('111', '/refresh'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Belum ada OLT C-Data'));
    }

    public function test_non_message_update_is_ignored(): void
    {
        Http::fake();
        $this->configureBot();

        $this->postWebhook(['update_id' => 2, 'edited_message' => ['text' => 'hi']])->assertOk();

        Http::assertNothingSent();
    }

    public function test_menu_command_sends_inline_keyboard(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        $this->postWebhook($this->update('111', '/menu'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'KusumaVision NMS')
            && isset($request['reply_markup']['inline_keyboard']));
    }

    public function test_los_command_lists_offline_onus(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);
        $this->seedOltWithOnus();

        $this->postWebhook($this->update('111', '/los'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'ONU LOS')
            && isset($request['reply_markup']['inline_keyboard']));
    }

    public function test_redaman_command_lists_high_attenuation(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);
        $this->seedOltWithOnus();

        $this->postWebhook($this->update('111', '/redaman'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'Redaman Tinggi'));
    }

    public function test_callback_navigates_and_edits_message_in_place(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);
        $olt = $this->seedOltWithOnus();

        $this->postWebhook($this->callbackUpdate('111', "o:{$olt->id}"))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && $request['callback_query_id'] === 'cbq-1');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && $request['chat_id'] === '111'
            && (int) $request['message_id'] === 50
            && str_contains($request['text'], 'OLT-C320-PATI')
            && isset($request['reply_markup']['inline_keyboard']));
    }

    public function test_callback_from_unauthorized_chat_is_denied(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);
        $this->seedOltWithOnus();

        $this->postWebhook($this->callbackUpdate('999', 'm'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Akses ditolak'));
        Http::assertNotSent(fn ($request) => str_contains($request['text'] ?? '', 'KusumaVision NMS'));
    }

    public function test_noop_callback_only_acknowledges(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        $this->postWebhook($this->callbackUpdate('111', 'noop'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'editMessageText'));
    }

    private function seedManyOnus(int $count): SnmpOlt
    {
        $onus = [];
        for ($i = 1; $i <= $count; $i++) {
            $onus[] = [
                'slot' => 1, 'port' => 1, 'onu_id' => $i, 'interface' => "gpon-onu_1/1/1:{$i}",
                'serial_number' => sprintf('ZTEG%08d', $i), 'name' => "Pelanggan {$i}",
                'online' => true, 'phase_state' => 'Working', 'rx_power_dbm' => -20.0,
            ];
        }

        return SnmpOlt::create([
            'name' => 'OLT-C320-PATI', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.7',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => ['ok' => true, 'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'count' => $count, 'onus' => $onus]]],
        ]);
    }

    public function test_search_lists_matches_with_pager(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        Str::createRandomStringsUsing(fn () => 'TESTTOKEN0');
        $this->configureBot(['chat_id' => '111']);
        $this->seedManyOnus(10);

        $this->postWebhook($this->update('111', '/search pelanggan'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage') || ! str_contains($request['text'], 'ONU cocok')) {
                return false;
            }
            $flat = json_encode($request['reply_markup']['inline_keyboard'] ?? []);

            return str_contains($flat, 'su:TESTTOKEN0:0:') && str_contains($flat, 'sr:TESTTOKEN0:1');
        });

        Str::createRandomStringsNormally();
    }

    public function test_search_pagination_and_detail_callbacks(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);
        $olt = $this->seedManyOnus(10);
        Cache::put('tg:search:TESTTOKEN0', 'pelanggan', 600);

        $this->postWebhook($this->callbackUpdate('111', 'sr:TESTTOKEN0:1'))->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && str_contains($request['text'], 'ONU cocok'));

        $this->postWebhook($this->callbackUpdate('111', "su:TESTTOKEN0:0:{$olt->id}:1:1:3"))->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && str_contains($request['text'], 'Pelanggan 3'));
    }

    public function test_expired_search_token_is_reported(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $this->configureBot(['chat_id' => '111']);

        $this->postWebhook($this->callbackUpdate('111', 'sr:GONE000000:0'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'kedaluwarsa'));
    }

    public function test_register_webhook_allows_callback_query_updates(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
        $admin = User::factory()->admin()->create();
        $this->configureBot(['webhook_secret' => null, 'commands_enabled' => false]);

        $this->actingAs($admin)->post(route('settings.telegram.webhook.register'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/setWebhook')
            && in_array('callback_query', (array) $request['allowed_updates'], true));
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
