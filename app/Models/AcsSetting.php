<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Genieacs\GenieacsDeviceSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Endpoint ACS / TR069 yang dipakai fitur "Aktifkan TR069 Massal" (ZTE).
 *
 * Singleton (satu baris). Password disimpan `encrypted` & `$hidden`. Bila baris
 * atau kolomnya kosong, {@see resolved()} jatuh balik ke `config('services.acs')`
 * (env `ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD`) supaya perilaku lama tetap jalan
 * sebelum admin mengisinya dari halaman Pengaturan.
 *
 * ACS ini milik staf Pusat dan hanya melayani OLT global non-demo
 * ({@see servesOlt()}) — OLT privat partner dan OLT demo tidak pernah menerima
 * URL/password-nya.
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
     * Apakah ACS di Pengaturan melayani OLT ini: OLT global (tanpa pemilik
     * partner) yang bukan demo. Aturan yang sama dipakai katalog GenieACS
     * ({@see GenieacsDeviceSyncService::isEligibleOlt()}),
     * jadi target CWMP dan katalog NBI tak pernah menyimpang.
     */
    public static function servesOlt(SnmpOlt $olt): bool
    {
        return ! $olt->is_demo && $olt->owner_user_id === null;
    }

    /**
     * Isi `acs_password` dari pengaturan server bila payload tidak membawanya.
     *
     * Password ACS tidak pernah dikirim ke browser/klien; form hanya
     * tahu `acs_password_set`. Bila operator sengaja mengetik password lain, nilai
     * itu dipakai apa adanya. Untuk OLT yang tidak dilayani ACS ini (OLT privat
     * partner, OLT demo) tidak ada yang diisikan — lihat {@see resolved()}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillPassword(array $data, string $key = 'acs_password', ?SnmpOlt $olt = null): array
    {
        if (($data[$key] ?? '') === '' || $data[$key] === null) {
            $resolved = static::resolved($olt)['password'];
            if ($resolved !== '') {
                $data[$key] = $resolved;
            }
        }

        return $data;
    }

    /**
     * Versi {@see fillPassword()} untuk request form (di-merge sebelum validasi).
     */
    public static function fillRequestPassword(Request $request, string $key = 'acs_password', ?SnmpOlt $olt = null): void
    {
        $filled = static::fillPassword([$key => $request->input($key)], $key, $olt);

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
     * Samarkan sandi ACS di teks CLI yang dikirim ke BROWSER (pratinjau, riwayat
     * registrasi, keluaran CLI yang menggemakan perintah, pesan galat). Skrip yang
     * dieksekusi/disimpan tetap utuh — penyamaran hanya di titik keluar.
     *
     * Menempel pada sintaks perintah (`validate basic username X password Y`), bukan nilai
     * sandi saat ini, supaya sandi lama di riwayat ikut tersamar. `[ \t]+` (bukan `\s+`) dan
     * `(?!tag\b)` menjaga sandi KOSONG tak menelan token berikutnya (C600: `password  tag pri`).
     */
    public static function maskScript(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_replace(
            '/(\bvalidate[ \t]+basic[ \t]+username[ \t]+\S+[ \t]+password[ \t]+)(?!tag\b)\S+/i',
            '$1********',
            $text,
        ) ?? $text;
    }

    /**
     * Target ACS (CWMP) efektif: pakai nilai tersimpan bila terisi, jika tidak
     * fallback ke config/env. Defensif terhadap tabel yang belum ada (fresh checkout).
     *
     * Dengan OLT: kosong untuk OLT yang tidak dilayani ACS ini ({@see servesOlt()}).
     * Password yang diisikan server berakhir apa adanya di script CLI (pratinjau,
     * riwayat registrasi) dan di running-config OLT — pada OLT privat partner itu
     * bisa dibaca partner lewat `show running-config`. Tanpa OLT: kosong untuk
     * pengguna login yang bukan staf Pusat; konteks tanpa pengguna (konsol,
     * antrean) memakai ACS ini.
     *
     * @return array{url:string, username:string, password:string}
     */
    public static function resolved(?SnmpOlt $olt = null): array
    {
        $empty = ['url' => '', 'username' => '', 'password' => ''];

        if ($olt !== null && ! static::servesOlt($olt)) {
            return $empty;
        }

        if ($olt === null && auth()->check()) {
            $user = auth()->user();

            if (! ($user instanceof User) || ! $user->isCentralStaff()) {
                return $empty;
            }
        }

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
