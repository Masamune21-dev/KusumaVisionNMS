<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\AlarmEvent;
use App\Models\PartnerTelegramBot;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman self-service "Bot Telegram Saya" untuk partner. Partner mengelola bot Telegram
 * sendiri (token, allow-list chat, filter alarm) + mendaftarkan webhook. Bot hanya menerima
 * alarm & melayani command untuk OLT yang di-assign ke partner (lihat {@see PartnerTelegramBot}).
 */
class TelegramBotController extends Controller
{
    public function edit(Request $request): Response
    {
        $bot = PartnerTelegramBot::forUser($request->user());

        // allowedOltIds() query pivot langsung (aman dari scope); jangan pakai relasi
        // partnerOlts() saat auth = partner.
        $assignedCount = count($request->user()->allowedOltIds());

        return Inertia::render('Partner/TelegramBot', [
            'bot' => [
                'enabled' => (bool) $bot->enabled,
                'bot_token_set' => filled($bot->bot_token),
                'chat_id' => $bot->chat_id,
                'min_severity' => $bot->min_severity ?? AlarmEvent::SEVERITY_WARNING,
                'notify_on_raise' => (bool) $bot->notify_on_raise,
                'notify_on_clear' => (bool) $bot->notify_on_clear,
                'notify_types' => $bot->notifyTypes(),
                'commands_enabled' => (bool) $bot->commands_enabled,
                'webhook_set' => filled($bot->webhook_secret),
                'last_sent_at' => $bot->last_sent_at?->toIso8601String(),
                'last_error' => $bot->last_error,
            ],
            'assignedOltCount' => $assignedCount,
            'severityOptions' => [
                ['value' => AlarmEvent::SEVERITY_WARNING, 'label' => 'Warning (semua alarm)'],
                ['value' => AlarmEvent::SEVERITY_MINOR, 'label' => 'Minor ke atas'],
                ['value' => AlarmEvent::SEVERITY_MAJOR, 'label' => 'Major ke atas'],
                ['value' => AlarmEvent::SEVERITY_CRITICAL, 'label' => 'Hanya Critical'],
            ],
            'alarmTypeOptions' => collect(AlarmEvent::TYPE_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'chat_id' => ['nullable', 'string', 'max:2000'],
            'min_severity' => ['required', Rule::in([
                AlarmEvent::SEVERITY_WARNING,
                AlarmEvent::SEVERITY_MINOR,
                AlarmEvent::SEVERITY_MAJOR,
                AlarmEvent::SEVERITY_CRITICAL,
            ])],
            'notify_on_raise' => ['boolean'],
            'notify_on_clear' => ['boolean'],
            'notify_types' => ['array'],
            'notify_types.*' => [Rule::in(AlarmEvent::types())],
            'commands_enabled' => ['boolean'],
        ]);

        $bot = PartnerTelegramBot::forUser($request->user());

        $bot->fill([
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'chat_id' => $validated['chat_id'] ?? null,
            'min_severity' => $validated['min_severity'],
            'notify_on_raise' => (bool) ($validated['notify_on_raise'] ?? false),
            'notify_on_clear' => (bool) ($validated['notify_on_clear'] ?? false),
            'commands_enabled' => (bool) ($validated['commands_enabled'] ?? false),
        ]);

        if ($request->has('notify_types')) {
            $bot->notify_types = array_values(
                array_intersect(AlarmEvent::types(), $validated['notify_types'] ?? [])
            );
        }

        // Field token kosong = pertahankan token yang ada.
        if (filled($validated['bot_token'] ?? null)) {
            $bot->bot_token = $validated['bot_token'];
        }

        $bot->save();

        return back()->with('success', 'Pengaturan bot Telegram tersimpan.');
    }

    public function test(Request $request, TelegramNotifier $notifier): RedirectResponse
    {
        $result = $notifier->sendTest(PartnerTelegramBot::forUser($request->user()));

        if ($result['ok']) {
            return back()->with('success', 'Pesan tes Telegram terkirim.');
        }

        return back()->with('error', 'Gagal mengirim tes Telegram: '.($result['error'] ?? 'unknown error'));
    }

    public function registerWebhook(Request $request, TelegramWebhookManager $manager): RedirectResponse
    {
        $result = $manager->register(PartnerTelegramBot::forUser($request->user()));

        if ($result['ok']) {
            return back()->with('success', 'Webhook Telegram terdaftar. Bot siap menerima perintah.');
        }

        return back()->with('error', 'Gagal mendaftarkan webhook: '.$result['message']);
    }

    public function deleteWebhook(Request $request, TelegramWebhookManager $manager): RedirectResponse
    {
        $result = $manager->delete(PartnerTelegramBot::forUser($request->user()));

        if ($result['ok']) {
            return back()->with('success', 'Webhook Telegram dihapus. Bot tidak lagi menerima perintah.');
        }

        return back()->with('error', 'Gagal menghapus webhook: '.$result['message']);
    }
}
