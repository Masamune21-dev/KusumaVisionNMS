<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\PartnerTelegramBot;
use App\Models\SnmpOlt;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bot Telegram per-partner: menerima alarm & melayani command HANYA untuk OLT yang
 * di-assign ke partner. Webhook memetakan {bot} → PartnerTelegramBot dan menyetel auth
 * user = partner sehingga query OLT ter-scope otomatis.
 */
class PartnerTelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $name, string $ip): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name, 'vendor' => 'ZTE C320', 'ip' => $ip,
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => ['ok' => true, 'port_onus' => ['1_1' => [
                'slot' => 1, 'port' => 1, 'count' => 1,
                'onus' => [['slot' => 1, 'port' => 1, 'onu_id' => 5, 'online' => true]],
            ]]],
        ]);
    }

    private function globalBot(): TelegramSetting
    {
        return TelegramSetting::create([
            'enabled' => true, 'bot_token' => '123:GLOBAL', 'chat_id' => '111',
            'webhook_secret' => 'global-secret', 'commands_enabled' => true, 'min_severity' => 'warning',
            'notify_on_raise' => true, 'notify_on_clear' => true,
        ]);
    }

    private function partnerBot(SnmpOlt $assigned): PartnerTelegramBot
    {
        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$assigned->id]);

        return PartnerTelegramBot::create([
            'user_id' => $partner->id,
            'enabled' => true, 'bot_token' => '999:PARTNER', 'chat_id' => '222',
            'webhook_secret' => 'partner-secret', 'commands_enabled' => true, 'min_severity' => 'warning',
            'notify_on_raise' => true, 'notify_on_clear' => true,
        ]);
    }

    private function alarmFor(SnmpOlt $olt): AlarmEvent
    {
        return AlarmEvent::create([
            'snmp_olt_id' => $olt->id, 'signature' => 'sig-'.$olt->id, 'type' => 'los',
            'severity' => AlarmEvent::SEVERITY_MAJOR, 'status' => AlarmEvent::STATUS_ACTIVE,
            'scope' => 'onu', 'message' => 'LOS terdeteksi', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_alarm_for_assigned_olt_reaches_partner_bot_and_global(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $this->globalBot();
        $this->partnerBot($olt);

        app(TelegramNotifier::class)->notify($olt, [$this->alarmFor($olt)], []);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot999:PARTNER/sendMessage') && $r['chat_id'] === '222');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot123:GLOBAL/sendMessage') && $r['chat_id'] === '111');
    }

    public function test_alarm_for_unassigned_olt_skips_partner_bot(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $assigned = $this->makeOlt('OLT-A', '10.8.0.1');
        $other = $this->makeOlt('OLT-B', '10.8.0.2');
        $this->globalBot();
        $this->partnerBot($assigned);

        app(TelegramNotifier::class)->notify($other, [$this->alarmFor($other)], []);

        // Global tetap dapat; bot partner TIDAK (OLT-B bukan miliknya).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot123:GLOBAL/sendMessage'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/bot999:PARTNER/'));
    }

    public function test_admin_alarm_off_silences_global_but_partner_still_receives(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $olt->forceFill(['alarms_enabled' => false])->save(); // admin matikan alarm OLT
        $this->globalBot();
        $this->partnerBot($olt); // saklar partner default on

        app(TelegramNotifier::class)->notify($olt, [$this->alarmFor($olt)], []);

        // Admin (bot global) TIDAK dapat; partner TETAP dapat (independen dari saklar admin).
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/bot123:GLOBAL/'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot999:PARTNER/sendMessage') && $r['chat_id'] === '222');
    }

    public function test_partner_alarm_off_silences_partner_bot_but_not_global(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $olt = $this->makeOlt('OLT-A', '10.8.0.1'); // saklar OLT (admin) default on
        $this->globalBot();
        $bot = $this->partnerBot($olt);
        DB::table('olt_user') // partner matikan alarm webhook-nya sendiri utk OLT ini
            ->where('user_id', $bot->user_id)
            ->where('snmp_olt_id', $olt->id)
            ->update(['alarms_enabled' => false]);

        app(TelegramNotifier::class)->notify($olt, [$this->alarmFor($olt)], []);

        // Global (admin) tetap dapat; bot partner TIDAK.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot123:GLOBAL/sendMessage'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/bot999:PARTNER/'));
    }

    public function test_private_owned_olt_alarm_reaches_only_partner_not_global(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $olt = $this->makeOlt('OLT-PRIVAT', '10.8.0.9');
        $this->globalBot();

        // OLT dijadikan privat milik partner (owner_user_id) + pivot + bot partner.
        $partner = User::factory()->partner()->create();
        $olt->forceFill(['owner_user_id' => $partner->id])->save();
        $partner->partnerOlts()->sync([$olt->id]);
        PartnerTelegramBot::create([
            'user_id' => $partner->id,
            'enabled' => true, 'bot_token' => '999:PARTNER', 'chat_id' => '222',
            'webhook_secret' => 'partner-secret', 'commands_enabled' => true, 'min_severity' => 'warning',
            'notify_on_raise' => true, 'notify_on_clear' => true,
        ]);

        app(TelegramNotifier::class)->notify($olt, [$this->alarmFor($olt)], []);

        // Bot partner pemilik dapat; bot global admin TIDAK (OLT privat).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot999:PARTNER/sendMessage') && $r['chat_id'] === '222');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/bot123:GLOBAL/'));
    }

    public function test_partner_webhook_uses_partner_bot_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $bot = $this->partnerBot($olt);

        $this->postJson(
            route('telegram.webhook', ['bot' => $bot->id]),
            ['update_id' => 1, 'message' => ['message_id' => 9, 'chat' => ['id' => '222', 'type' => 'private'], 'text' => '/ping']],
            ['X-Telegram-Bot-Api-Secret-Token' => 'partner-secret'],
        )->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot999:PARTNER/sendMessage') && str_contains($r['text'], 'Pong'));
    }

    public function test_partner_webhook_rejects_wrong_secret(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $bot = $this->partnerBot($olt);

        $this->postJson(
            route('telegram.webhook', ['bot' => $bot->id]),
            ['update_id' => 1, 'message' => ['message_id' => 9, 'chat' => ['id' => '222', 'type' => 'private'], 'text' => '/ping']],
            ['X-Telegram-Bot-Api-Secret-Token' => 'global-secret'],
        )->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_unknown_partner_bot_id_is_rejected(): void
    {
        Http::fake();

        $this->postJson(
            route('telegram.webhook', ['bot' => 999999]),
            ['update_id' => 1, 'message' => ['message_id' => 9, 'chat' => ['id' => '222', 'type' => 'private'], 'text' => '/ping']],
            ['X-Telegram-Bot-Api-Secret-Token' => 'x'],
        )->assertForbidden();

        Http::assertNothingSent();
    }
}
