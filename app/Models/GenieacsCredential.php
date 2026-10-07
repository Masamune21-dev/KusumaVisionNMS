<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Genieacs\GenieACSService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Endpoint NBI GenieACS (port 7557) yang dipakai NMS membaca katalog device TR-069.
 *
 * Singleton (satu baris). Password disimpan `encrypted` & `$hidden` — tidak pernah
 * dikirim ke browser; form hanya tahu `password_set`, sama seperti password ACS
 * di {@see AcsSetting}.
 *
 * Berbeda peran dengan {@see AcsSetting}: AcsSetting menyimpan URL CWMP yang
 * ditanam ke ONU, model ini menyimpan alamat NBI untuk dashboard.
 */
class GenieacsCredential extends Model
{
    use Auditable;

    protected $fillable = [
        'host',
        'port',
        'username',
        'password',
        'role',
        'is_connected',
        'last_test_at',
        'last_test_error',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'is_connected' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return 'Pengaturan GenieACS (NBI)';
    }

    public function auditTitle(): string
    {
        return '';
    }

    /**
     * Baris singleton (atau instance baru yang belum tersimpan bila belum ada).
     */
    public static function instance(): self
    {
        return static::query()->firstOrNew([]);
    }

    /**
     * Sudah diisi host-nya? Dipakai UI untuk menandai modul GenieACS aktif.
     */
    public static function isConfigured(): bool
    {
        try {
            return filled(static::instance()->host);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Isi `genieacs_password` dari pengaturan tersimpan bila payload tidak
     * membawanya, meniru {@see AcsSetting::fillPassword()}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillPassword(array $data, string $key = 'genieacs_password'): array
    {
        if (($data[$key] ?? '') === '' || $data[$key] === null) {
            $stored = (string) (static::instance()->password ?? '');
            if ($stored !== '') {
                $data[$key] = $stored;
            }
        }

        return $data;
    }

    /**
     * Versi {@see fillPassword()} untuk request form (di-merge sebelum validasi).
     */
    public static function fillRequestPassword(Request $request, string $key = 'genieacs_password'): void
    {
        $filled = static::fillPassword([$key => $request->input($key)], $key);

        if (($filled[$key] ?? '') !== '' && $filled[$key] !== $request->input($key)) {
            $request->merge([$key => $filled[$key]]);
        }
    }

    /**
     * Klien NBI dari baris tersimpan. Null bila host belum diisi.
     *
     * Timeout sengaja pendek untuk pemakaian dari HTTP request (uji koneksi,
     * pencocokan); pemanggil batch boleh menaikkannya sendiri.
     */
    public static function client(int $requestTimeout = 30, int $connectTimeout = 5): ?GenieACSService
    {
        $setting = static::instance();

        if (blank($setting->host)) {
            return null;
        }

        return new GenieACSService(
            (string) $setting->host,
            (int) ($setting->port ?: 7557),
            filled($setting->username) ? (string) $setting->username : null,
            filled($setting->password) ? (string) $setting->password : null,
            $requestTimeout,
            $connectTimeout,
        );
    }
}
