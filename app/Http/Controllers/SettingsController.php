<?php

namespace App\Http\Controllers;

use App\Models\AcsSetting;
use App\Models\AlarmEvent;
use App\Models\AlarmSetting;
use App\Models\FcmDeviceToken;
use App\Models\FcmSetting;
use App\Models\GeneralSetting;
use App\Models\TelegramSetting;
use App\Services\Fcm\FcmAlarmNotifier;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $alarm = AlarmSetting::instance();

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
                'default_url' => (string) config('services.acs.url', ''),
                'default_username' => (string) config('services.acs.username', ''),
            ],
            'appInfo' => $this->appInfoPayload(),
            'mobileApk' => $this->mobileApkPayload(),
            'alarm' => [
                'confirm_before_notify' => (bool) $alarm->confirm_before_notify,
            ],
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
            return back()->with('error', __('flash.api_disabled'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $token = $request->user()->createToken($validated['name']);

        return back()
            ->with('apiToken', $token->plainTextToken)
            ->with('success', __('flash.token_created'));
    }

    /**
     * Cabut satu token API milik user yang sedang login.
     */
    public function revokeApiToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('success', __('flash.token_revoked'));
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

        return back()->with('success', __('flash.general_saved'));
    }

    /**
     * Simpan perilaku alarm global (debounce 2 poll vs realtime).
     * true  = konfirmasi 2 poll dulu sebelum kirim notifikasi (anti-flap, default).
     * false = realtime: kirim langsung saat fault pertama terdeteksi.
     * Berlaku untuk semua OLT & semua kanal (Telegram + FCM), efektif pada poll berikutnya.
     */
    public function updateAlarm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'confirm_before_notify' => ['boolean'],
        ]);

        $setting = AlarmSetting::instance();
        $setting->confirm_before_notify = (bool) ($validated['confirm_before_notify'] ?? false);
        $setting->save();

        return back()->with('success', __('flash.alarm_saved'));
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

        return back()->with('success', __('flash.acs_saved'));
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

        return back()->with('success', __('flash.telegram_saved'));
    }

    public function testTelegram(TelegramNotifier $notifier): RedirectResponse
    {
        $result = $notifier->sendTest();

        if ($result['ok']) {
            return back()->with('success', __('flash.telegram_test_sent'));
        }

        return back()->with('error', __('flash.telegram_test_failed').($result['error'] ?? 'unknown error'));
    }

    public function registerWebhook(TelegramWebhookManager $manager): RedirectResponse
    {
        $result = $manager->register();

        if ($result['ok']) {
            return back()->with('success', __('flash.webhook_registered'));
        }

        return back()->with('error', __('flash.webhook_register_failed').$result['message']);
    }

    public function deleteWebhook(TelegramWebhookManager $manager): RedirectResponse
    {
        $result = $manager->delete();

        if ($result['ok']) {
            return back()->with('success', __('flash.webhook_deleted'));
        }

        return back()->with('error', __('flash.webhook_delete_failed').$result['message']);
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

        return back()->with('success', __('flash.fcm_saved'));
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
            return back()->with('error', __('flash.fcm_not_configured'));
        }

        $res = $notifier->broadcast($validated['title'], $validated['body']);

        if ($res['ok']) {
            return back()->with('success', __('flash.fcm_sent', ['n' => $res['sent']]));
        }

        $reason = match ($res['reason'] ?? null) {
            'no_tokens' => __('flash.fcm_no_devices'),
            default => __('flash.fcm_send_failed').($res['error'] ?? __('flash.unknown')),
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

    /**
     * Info APK Android terbaru untuk tombol unduh di Settings.
     * File "latest" disalin oleh bin/build-apk.sh ke public/downloads/kusumavision-nms.apk.
     */
    private function mobileApkPayload(): array
    {
        $path = public_path('downloads/kusumavision-nms.apk');
        $exists = is_file($path);

        return [
            'available' => $exists,
            'url' => $exists ? url('/downloads/kusumavision-nms.apk') : null,
            'version' => $this->mobileAppVersion(),
            'size' => $exists ? $this->humanFilesize((int) filesize($path)) : null,
            'updated_at' => $exists
                ? Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String()
                : null,
        ];
    }

    /** Baca "version:" dari mobile/pubspec.yaml (mis. 1.1.4+8) bila repo mobile tersedia. */
    private function mobileAppVersion(): ?string
    {
        $pubspec = base_path('mobile/pubspec.yaml');
        if (! is_file($pubspec)) {
            return null;
        }

        $contents = @file_get_contents($pubspec);
        if ($contents !== false && preg_match('/^version:\s*(.+)$/m', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function humanFilesize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
