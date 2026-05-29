<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class GeneralSetting extends Model
{
    use Auditable;

    public const DEFAULT_NAME = 'KusumaVision';

    public const DEFAULT_VERSION = '2.0.0';

    public const CACHE_KEY = 'general_settings.branding';

    protected $fillable = [
        'app_name',
        'app_version',
        'logo_path',
    ];

    public function auditLabel(): string
    {
        return 'Pengaturan Umum';
    }

    public function auditTitle(): string
    {
        return '';
    }

    /**
     * The singleton settings row (or a fresh unsaved instance with defaults).
     */
    public static function instance(): self
    {
        return static::query()->firstOrNew([], [
            'app_name' => self::DEFAULT_NAME,
            'app_version' => self::DEFAULT_VERSION,
        ]);
    }

    /**
     * Public URL for the uploaded logo, or null when using the built-in SVG.
     */
    public function logoUrl(): ?string
    {
        return filled($this->logo_path)
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }

    /**
     * Branding payload shared with the frontend. Cached and defensive so the
     * app keeps rendering even before the table exists (fresh checkout/tests).
     *
     * @return array{name:string, version:string, logo_url:string|null}
     */
    public static function brandingPayload(): array
    {
        return cache()->remember(self::CACHE_KEY, 3600, function (): array {
            try {
                $setting = static::instance();

                return [
                    'name' => $setting->app_name ?: self::DEFAULT_NAME,
                    'version' => $setting->app_version ?: self::DEFAULT_VERSION,
                    'logo_url' => $setting->logoUrl(),
                ];
            } catch (\Throwable) {
                return [
                    'name' => self::DEFAULT_NAME,
                    'version' => self::DEFAULT_VERSION,
                    'logo_url' => null,
                ];
            }
        });
    }

    protected static function booted(): void
    {
        $forget = fn () => cache()->forget(self::CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }
}
