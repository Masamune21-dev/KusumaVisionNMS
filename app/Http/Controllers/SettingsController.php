<?php

namespace App\Http\Controllers;

use App\Models\AcsSetting;
use App\Models\AlarmEvent;
use App\Models\FcmDeviceToken;
use App\Models\FcmSetting;
use App\Models\GeneralSetting;
use App\Models\TelegramSetting;
use App\Services\Fcm\FcmAlarmNotifier;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $setting = TelegramSetting::instance();
        $general = GeneralSetting::instance();
        $acs = AcsSetting::instance();
        $fcm = FcmSetting::instance();

        return Inertia::render('Settings/Index', [
            'api' => [
                // API aktif hanya bila rute benar-benar terdaftar (saklar di routes/api.php).
                'enabled' => Route::has('api.public.status'),
                'base_url' => url('/api/v1'),
                'public_status_url' => url('/api/v1/public/status'),
                // Token plain-text hanya tampil sekali (flash setelah dibuat).
                'new_token' => $request->session()->get('apiToken'),
                'tokens' => $this->apiTokensPayload($request),
            ],
            'general' => [
                'app_name' => $general->app_name ?? GeneralSetting::DEFAULT_NAME,
                'app_version' => $general->app_version ?? GeneralSetting::DEFAULT_VERSION,
                'logo_url' => $general->logoUrl(),
            ],
            'acs' => [
                'url' => $acs->url ?? '',
                'username' => $acs->username ?? '',
                'password_set' => filled($acs->password),
                'default_url' => (string) config('services.acs.url', 'http://acs.bmkv.net:7547'),
                'default_username' => (string) config('services.acs.username', 'cms'),
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
            'fcm' => [
                // Kredensial Firebase terpasang di server (kapabilitas teknis).
                'credentials_ready' => app(FcmAlarmNotifier::class)->enabled(),
                'enabled' => (bool) $fcm->enabled,
                'min_severity' => $fcm->min_severity ?? AlarmEvent::SEVERITY_MAJOR,
                'notify_on_raise' => (bool) $fcm->notify_on_raise,
                'notify_on_clear' => (bool) $fcm->notify_on_clear,
                'notify_types' => $fcm->notifyTypes(),
                'device_count' => FcmDeviceToken::query()->count(),
                'last_sent_at' => $fcm->last_sent_at?->toIso8601String(),
                'last_error' => $fcm->last_error,
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

    /**
     * Terbitkan token API (Sanctum) milik user yang sedang login.
     * Plain-text token hanya dikirim balik sekali via flash.
     */
    public function createApiToken(Request $request): RedirectResponse
    {
        // API dimatikan di server → token tak ada gunanya; tolak agar tak menumpuk token nganggur.
        if (! Route::has('api.public.status')) {
            return back()->with('error', 'API sedang dinonaktifkan. Aktifkan dulu di server (routes/api.php) sebelum membuat token.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $token = $request->user()->createToken($validated['name']);

        return back()
            ->with('apiToken', $token->plainTextToken)
            ->with('success', 'Token API dibuat. Salin sekarang — token hanya ditampilkan satu kali.');
    }

    /**
     * Cabut satu token API milik user yang sedang login.
     */
    public function revokeApiToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('success', 'Token API dicabut.');
    }

    /**
     * Daftar token API milik user (tanpa nilai token; itu tak pernah disimpan plain).
     *
     * @return array<int, array<string, mixed>>
     */
    private function apiTokensPayload(Request $request): array
    {
        // Hindari 500 bila tabel token belum dimigrasikan di server.
        if (! Schema::hasTable('personal_access_tokens')) {
            return [];
        }

        return $request->user()->tokens()
            ->latest()
            ->get(['id', 'name', 'last_used_at', 'created_at'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->all();
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

    /**
     * Simpan endpoint ACS / TR069 (dipakai fitur "Aktifkan TR069 Massal").
     * Password kosong = pertahankan yang lama.
     */
    public function updateAcs(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:255', 'url'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        $setting = AcsSetting::instance();
        $setting->url = $validated['url'];
        $setting->username = $validated['username'];

        // Field password kosong berarti "pertahankan password lama".
        if (filled($validated['password'] ?? null)) {
            $setting->password = $validated['password'];
        }

        $setting->save();

        return back()->with('success', 'Pengaturan ACS / TR069 tersimpan.');
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
     * Simpan pengaturan push notifikasi mobile (FCM).
     */
    public function updateFcm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
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
        ]);

        $setting = FcmSetting::instance();

        $setting->fill([
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'min_severity' => $validated['min_severity'],
            'notify_on_raise' => (bool) ($validated['notify_on_raise'] ?? false),
            'notify_on_clear' => (bool) ($validated['notify_on_clear'] ?? false),
        ]);

        if ($request->has('notify_types')) {
            $setting->notify_types = array_values(
                array_intersect(AlarmEvent::types(), $validated['notify_types'] ?? [])
            );
        }

        $setting->save();

        return back()->with('success', 'Pengaturan notifikasi mobile tersimpan.');
    }

    /**
     * Kirim notifikasi manual dari web ke semua aplikasi mobile terdaftar.
     */
    public function sendFcmManual(Request $request, FcmAlarmNotifier $notifier): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
        ]);

        if (! $notifier->enabled()) {
            return back()->with('error', 'Push FCM belum dikonfigurasi di server (kredensial Firebase belum dipasang).');
        }

        $res = $notifier->broadcast($validated['title'], $validated['body']);

        if ($res['ok']) {
            return back()->with('success', 'Notifikasi terkirim ke '.$res['sent'].' perangkat.');
        }

        $reason = match ($res['reason'] ?? null) {
            'no_tokens' => 'Belum ada perangkat mobile yang terdaftar.',
            default => 'Gagal mengirim: '.($res['error'] ?? 'tidak diketahui'),
        };

        return back()->with('error', $reason);
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
