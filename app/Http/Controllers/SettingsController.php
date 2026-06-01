<?php

namespace App\Http\Controllers;

use App\Models\AlarmEvent;
use App\Models\GeneralSetting;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        $setting = TelegramSetting::instance();
        $general = GeneralSetting::instance();

        return Inertia::render('Settings/Index', [
            'general' => [
                'app_name' => $general->app_name ?? GeneralSetting::DEFAULT_NAME,
                'app_version' => $general->app_version ?? GeneralSetting::DEFAULT_VERSION,
                'logo_url' => $general->logoUrl(),
            ],
            'appInfo' => $this->appInfoPayload(),
            'telegram' => [
                'enabled' => (bool) $setting->enabled,
                'bot_token_set' => filled($setting->bot_token),
                'chat_id' => $setting->chat_id,
                'min_severity' => $setting->min_severity ?? AlarmEvent::SEVERITY_WARNING,
                'notify_on_raise' => (bool) $setting->notify_on_raise,
                'notify_on_clear' => (bool) $setting->notify_on_clear,
                'notify_types' => $setting->notifyTypes(),
                'commands_enabled' => (bool) $setting->commands_enabled,
                'webhook_set' => filled($setting->webhook_secret),
                'last_sent_at' => $setting->last_sent_at?->toIso8601String(),
                'last_error' => $setting->last_error,
            ],
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

    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:60'],
            'app_version' => ['required', 'string', 'max:30'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
            'remove_logo' => ['boolean'],
        ]);

        $setting = GeneralSetting::instance();

        $setting->fill([
            'app_name' => $validated['app_name'],
            'app_version' => $validated['app_version'],
        ]);

        // Replace the logo when a new file is uploaded, or clear it on request.
        if ($request->hasFile('logo')) {
            if (filled($setting->logo_path)) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $setting->logo_path = $request->file('logo')->store('branding', 'public');
        } elseif ($validated['remove_logo'] ?? false) {
            if (filled($setting->logo_path)) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $setting->logo_path = null;
        }

        $setting->save();

        return back()->with('success', 'Pengaturan umum tersimpan.');
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
            'notify_types' => ['array'],
            'notify_types.*' => [Rule::in(AlarmEvent::types())],
            'commands_enabled' => ['boolean'],
        ]);

        $setting = TelegramSetting::instance();

        $setting->fill([
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'chat_id' => $validated['chat_id'] ?? null,
            'min_severity' => $validated['min_severity'],
            'notify_on_raise' => (bool) ($validated['notify_on_raise'] ?? false),
            'notify_on_clear' => (bool) ($validated['notify_on_clear'] ?? false),
            'commands_enabled' => (bool) ($validated['commands_enabled'] ?? false),
        ]);

        // Only touch the per-type filter when the form actually submits it. An absent
        // field keeps the existing set (null = all); an explicit (even empty) array is
        // stored normalised to known types in canonical order.
        if ($request->has('notify_types')) {
            $setting->notify_types = array_values(
                array_intersect(AlarmEvent::types(), $validated['notify_types'] ?? [])
            );
        }

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

    public function registerWebhook(TelegramWebhookManager $manager): RedirectResponse
    {
        $result = $manager->register();

        if ($result['ok']) {
            return back()->with('success', 'Webhook Telegram terdaftar. Bot siap menerima perintah.');
        }

        return back()->with('error', 'Gagal mendaftarkan webhook: '.$result['message']);
    }

    public function deleteWebhook(TelegramWebhookManager $manager): RedirectResponse
    {
        $result = $manager->delete();

        if ($result['ok']) {
            return back()->with('success', 'Webhook Telegram dihapus. Bot tidak lagi menerima perintah.');
        }

        return back()->with('error', 'Gagal menghapus webhook: '.$result['message']);
    }

    /**
     * Read-only application & tech-stack information for the General tab.
     *
     * @return array{description:string, owner:string, stack:array<int,array{label:string, value:string}>}
     */
    private function appInfoPayload(): array
    {
        return [
            'description' => 'Network Management System FTTH/GPON untuk OLT ZTE & provisioning ONU.',
            'owner' => 'PT BERKAH MEDIA KUSUMA VISION (BMKV)',
            'stack' => [
                ['label' => 'Backend', 'value' => 'Laravel '.app()->version()],
                ['label' => 'PHP', 'value' => PHP_VERSION],
                ['label' => 'Frontend', 'value' => 'Vue 3 + Inertia.js'],
                ['label' => 'Styling', 'value' => 'Tailwind CSS'],
                ['label' => 'Database', 'value' => 'PostgreSQL'],
                ['label' => 'Cache & Queue', 'value' => 'Redis'],
                ['label' => 'SNMP Poller', 'value' => 'Go Engine'],
                ['label' => 'Server', 'value' => php_uname('s').' '.php_uname('r')],
            ],
        ];
    }
}
