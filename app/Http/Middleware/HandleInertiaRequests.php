<?php

namespace App\Http\Middleware;

use App\Models\GeneralSetting;
use App\Services\Alarm\AlarmNotificationService;
use App\Support\Locale;
use App\Support\Theme;
use Illuminate\Http\Request;
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
                    'manage_acs' => (bool) $user?->canManageAcs(),
                    'is_partner' => (bool) $user?->isPartner(),
                    'is_demo' => (bool) $user?->isDemo(),
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'notifications' => fn () => $this->notificationsPayload($request),
            'branding' => fn () => GeneralSetting::brandingPayload(),
            'locale' => app()->getLocale(),
            // Pilihan tema mentah (dark|light|system); lihat App\Support\Theme.
            'theme' => Theme::preference($request),
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

        return app(AlarmNotificationService::class)->payloadFor($user);
    }
}
