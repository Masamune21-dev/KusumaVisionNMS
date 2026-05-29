<?php

namespace App\Http\Middleware;

use App\Models\AlarmEvent;
use App\Models\GeneralSetting;
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
     * @return array{version:string, uptime:string, users_online:int}
     */
    private function systemInfoPayload(): array
    {
        return [
            'version' => GeneralSetting::brandingPayload()['version'],
            'uptime' => $this->formatUptime(),
            'users_online' => $this->estimateActiveUsers(),
        ];
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
