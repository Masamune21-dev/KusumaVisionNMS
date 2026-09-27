<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Endpoint ACS / TR069 yang dipakai fitur "Aktifkan TR069 Massal" (ZTE).
 *
 * Singleton (satu baris). Password disimpan `encrypted` & `$hidden`. Bila baris
 * atau kolomnya kosong, {@see resolved()} jatuh balik ke `config('services.acs')`
 * (env `ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD`) supaya perilaku lama tetap jalan
 * sebelum admin mengisinya dari halaman Pengaturan.
 */
class AcsSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'url',
        'username',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public function auditLabel(): string
    {
        return 'Pengaturan ACS / TR069';
    }

    public function auditTitle(): string
    {
        return '';
    }

    /**
     * The singleton settings row (or a fresh unsaved instance if none exists yet).
     */
    public static function instance(): self
    {
        return static::query()->firstOrNew([]);
    }

    /**
     * Isi `acs_password` dari pengaturan server bila payload tidak membawanya.
     *
     * Password ACS tidak pernah dikirim ke browser/klien; form hanya
     * tahu `acs_password_set`. Bila operator sengaja mengetik password lain, nilai
     * itu dipakai apa adanya.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillPassword(array $data, string $key = 'acs_password'): array
    {
        if (($data[$key] ?? '') === '' || $data[$key] === null) {
            $resolved = static::resolved()['password'];
            if ($resolved !== '') {
                $data[$key] = $resolved;
            }
        }

        return $data;
    }

    /**
     * Versi {@see fillPassword()} untuk request form (di-merge sebelum validasi).
     */
    public static function fillRequestPassword(Request $request, string $key = 'acs_password'): void
    {
        $filled = static::fillPassword([$key => $request->input($key)], $key);

        if (($filled[$key] ?? '') !== '' && $filled[$key] !== $request->input($key)) {
            if (str_contains($key, '.')) {
                [$parent, $child] = explode('.', $key, 2);
                $nested = (array) $request->input($parent, []);
                $nested[$child] = $filled[$key];
                $request->merge([$parent => $nested]);
            } else {
                $request->merge([$key => $filled[$key]]);
            }
        }
    }

    /**
     * Target ACS efektif: pakai nilai tersimpan bila terisi, jika tidak fallback
     * ke config/env. Defensif terhadap tabel yang belum ada (fresh checkout).
     *
     * @return array{url:string, username:string, password:string}
     */
    public static function resolved(): array
    {
        try {
            $setting = static::instance();
        } catch (\Throwable) {
            $setting = new self;
        }

        return [
            'url' => filled($setting->url)
                ? (string) $setting->url
                : (string) config('services.acs.url', ''),
            'username' => filled($setting->username)
                ? (string) $setting->username
                : (string) config('services.acs.username', ''),
            'password' => filled($setting->password)
                ? (string) $setting->password
                : (string) config('services.acs.password', ''),
        ];
    }
}
