<?php

namespace App\Http\Controllers;

use App\Models\AlarmEvent;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        $setting = TelegramSetting::instance();

        return Inertia::render('Settings/Index', [
            'telegram' => [
                'enabled' => (bool) $setting->enabled,
                'bot_token_set' => filled($setting->bot_token),
                'chat_id' => $setting->chat_id,
                'min_severity' => $setting->min_severity ?? AlarmEvent::SEVERITY_WARNING,
                'notify_on_raise' => (bool) $setting->notify_on_raise,
                'notify_on_clear' => (bool) $setting->notify_on_clear,
                'last_sent_at' => $setting->last_sent_at?->toIso8601String(),
                'last_error' => $setting->last_error,
            ],
            'severityOptions' => [
                ['value' => AlarmEvent::SEVERITY_WARNING, 'label' => 'Warning (semua alarm)'],
                ['value' => AlarmEvent::SEVERITY_MINOR, 'label' => 'Minor ke atas'],
                ['value' => AlarmEvent::SEVERITY_MAJOR, 'label' => 'Major ke atas'],
                ['value' => AlarmEvent::SEVERITY_CRITICAL, 'label' => 'Hanya Critical'],
            ],
        ]);
    }

    public function updateTelegram(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'chat_id' => ['nullable', 'string', 'max:255'],
            'min_severity' => ['required', Rule::in([
                AlarmEvent::SEVERITY_WARNING,
                AlarmEvent::SEVERITY_MINOR,
                AlarmEvent::SEVERITY_MAJOR,
                AlarmEvent::SEVERITY_CRITICAL,
            ])],
            'notify_on_raise' => ['boolean'],
            'notify_on_clear' => ['boolean'],
        ]);

        $setting = TelegramSetting::instance();

        $setting->fill([
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'chat_id' => $validated['chat_id'] ?? null,
            'min_severity' => $validated['min_severity'],
            'notify_on_raise' => (bool) ($validated['notify_on_raise'] ?? false),
            'notify_on_clear' => (bool) ($validated['notify_on_clear'] ?? false),
        ]);

        // Empty token field means "keep the existing token".
        if (filled($validated['bot_token'] ?? null)) {
            $setting->bot_token = $validated['bot_token'];
        }

        $setting->save();

        return back()->with('success', 'Pengaturan Telegram tersimpan.');
    }

    public function testTelegram(TelegramNotifier $notifier): RedirectResponse
    {
        $result = $notifier->sendTest();

        if ($result['ok']) {
            return back()->with('success', 'Pesan tes Telegram terkirim.');
        }

        return back()->with('error', 'Gagal mengirim tes Telegram: '.($result['error'] ?? 'unknown error'));
    }
}
