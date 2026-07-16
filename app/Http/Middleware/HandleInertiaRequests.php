<?php

namespace App\Http\Middleware;

use App\Models\AlarmEvent;
use App\Models\GeneralSetting;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'can' => [
                    'manage_users' => (bool) $user?->canManageUsers(),
                    'manage_olt' => (bool) $user?->canManageOlt(),
                    'manage_olt_inventory' => (bool) $user?->canManageOltInventory(),
                    'add_olt' => (bool) $user?->canAddOlt(),
                    'is_partner' => (bool) $user?->isPartner(),
                    'is_demo' => (bool) $user?->isDemo(),
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'notifications' => fn () => $this->notificationsPayload($request),
            'systemInfo' => fn () => $this->systemInfoPayload(),
            'branding' => fn () => GeneralSetting::brandingPayload(),
            'locale' => app()->getLocale(),
            'locales' => Locale::options(),
        ];
    }

    /**
     * @return array{items:array<int,array<string,mixed>>, unread_count:int}
     */
    private function notificationsPayload(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return ['items' => [], 'unread_count' => 0];
        }

        $items = AlarmEvent::query()
            ->with('olt:id,name')
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->get()
            ->map(fn (AlarmEvent $a) => [
                'id' => $a->id,
                'olt_name' => $a->olt?->name,
                'severity' => $a->severity,
                'message' => $a->message,
                'created_at' => $a->last_seen_at?->toIso8601String(),
                'read_at' => $user->last_notifications_read_at
                    && $a->last_seen_at <= $user->last_notifications_read_at
                        ? $user->last_notifications_read_at->toIso8601String()
                        : null,
            ])
            ->all();

        $unreadCount = collect($items)->whereNull('read_at')->count();

        return [
            'items' => $items,
            'unread_count' => $unreadCount,
        ];
    }

    /**
     * @return array{version:string, uptime:string, users_online:int, health:array<string,mixed>}
     */
    private function systemInfoPayload(): array
    {
        return [
            'version' => GeneralSetting::brandingPayload()['version'],
            'uptime' => $this->formatUptime(),
            'users_online' => $this->estimateActiveUsers(),
            'health' => $this->serverHealth(),
        ];
    }

    /**
     * Kesehatan server (CPU/RAM/disk). Di-cache singkat agar tak membaca /proc
     * tiap request. Tiap metrik null bila tak terbaca (mis. non-Linux) → UI sembunyi.
     *
     * @return array{cpu:?array<string,mixed>, memory:?array<string,mixed>, disk:?array<string,mixed>}
     */
    private function serverHealth(): array
    {
        return cache()->remember('dashboard.server_health', 5, fn () => [
            'cpu' => $this->cpuHealth(),
            'memory' => $this->memoryHealth(),
            'disk' => $this->diskHealth(),
        ]);
    }

    /**
     * CPU sebagai persen load average 1-menit dinormalkan jumlah core.
     *
     * @return array{percent:?int, load:float, cores:int}|null
     */
    private function cpuHealth(): ?array
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();
        if (! is_array($load) || ! isset($load[0])) {
            return null;
        }

        $cores = $this->cpuCores();
        $load1 = (float) $load[0];

        return [
            'percent' => $cores > 0 ? (int) min(100, round($load1 / $cores * 100)) : null,
            'load' => round($load1, 2),
            'cores' => $cores,
        ];
    }

    private function cpuCores(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $contents = @file_get_contents('/proc/cpuinfo');
            if ($contents !== false) {
                $count = preg_match_all('/^processor\s*:/m', $contents);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        return 1;
    }

    /**
     * @return array{percent:int, used:string, total:string}|null
     */
    private function memoryHealth(): ?array
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $contents = @file_get_contents('/proc/meminfo');
        if ($contents === false) {
            return null;
        }

        $field = function (string $key) use ($contents): ?int {
            if (preg_match('/^'.preg_quote($key, '/').':\s+(\d+)\s+kB/m', $contents, $m)) {
                return (int) $m[1]; // kB
            }

            return null;
        };

        $totalKb = $field('MemTotal');
        $availKb = $field('MemAvailable') ?? $field('MemFree');
        if (! $totalKb || $availKb === null) {
            return null;
        }

        $usedKb = max(0, $totalKb - $availKb);

        return [
            'percent' => (int) round($usedKb / $totalKb * 100),
            'used' => $this->humanBytes($usedKb * 1024),
            'total' => $this->humanBytes($totalKb * 1024),
        ];
    }

    /**
     * @return array{percent:int, used:string, total:string}|null
     */
    private function diskHealth(): ?array
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || ! $total) {
            return null;
        }

        $used = max(0, $total - $free);

        return [
            'percent' => (int) round($used / $total * 100),
            'used' => $this->humanBytes($used),
            'total' => $this->humanBytes($total),
        ];
    }

    private function humanBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        $decimals = ($i >= 2 && $bytes < 100) ? 1 : 0;

        return number_format($bytes, $decimals).' '.$units[$i];
    }

    private function formatUptime(): string
    {
        $seconds = 0;
        if (is_readable('/proc/uptime')) {
            $contents = @file_get_contents('/proc/uptime');
            if ($contents !== false) {
                $seconds = (int) floatval(explode(' ', trim($contents))[0] ?? 0);
            }
        }

        if ($seconds <= 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        if ($days === 0 && $hours === 0) {
            $minutes = intdiv($seconds % 3600, 60);

            return "{$minutes} menit";
        }

        return "{$days} hari, {$hours} jam";
    }

    private function estimateActiveUsers(): int
    {
        return cache()->remember('dashboard.users_online', 30, function () {
            if (config('session.driver') !== 'database') {
                return 1;
            }
            try {
                return (int) DB::table(config('session.table', 'sessions'))
                    ->whereNotNull('user_id')
                    ->where('last_activity', '>=', time() - 300)
                    ->distinct('user_id')
                    ->count('user_id');
            } catch (\Throwable) {
                return 1;
            }
        });
    }
}
