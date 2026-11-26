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

    /*
    |--------------------------------------------------------------------------
    | Atribusi pemilik — nilai awal (dapat diubah di Pengaturan)
    |--------------------------------------------------------------------------
    | OWNER dipakai sebagai default saat row settings belum dibuat / kolom
    | kosong. Nilai aktif disimpan di `general_settings.owner` dan diekspos
    | lewat brandingPayload() supaya footer & dokumen mengikuti white-label.
    */
    public const OWNER = 'PT Berkah Media Kusuma Vision';

    public const OWNER_SHORT = 'BMKV';

    public const COPYRIGHT_YEAR = '2026';

    protected $fillable = [
        'app_name',
        'app_version',
        'owner',
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
            'owner' => self::OWNER,
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
     * `owner` & `copyright_year` selalu berasal dari konstanta (permanen), tidak
     * dari database — lihat catatan atribusi pemilik di atas.
     *
     * @return array{name:string, version:string, logo_url:string|null, owner:string, owner_short:string, copyright_year:string}
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
                    'owner' => $setting->owner ?: self::OWNER,
                    'owner_short' => self::OWNER_SHORT,
                    'copyright_year' => self::COPYRIGHT_YEAR,
                ];
            } catch (\Throwable) {
                return [
                    'name' => self::DEFAULT_NAME,
                    'version' => self::DEFAULT_VERSION,
                    'logo_url' => null,
                    'owner' => self::OWNER,
                    'owner_short' => self::OWNER_SHORT,
                    'copyright_year' => self::COPYRIGHT_YEAR,
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
