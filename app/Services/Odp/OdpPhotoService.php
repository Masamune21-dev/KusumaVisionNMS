<?php

namespace App\Services\Odp;

use App\Models\Odp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Foto dokumentasi ODP (satu foto per ODP; upload baru menimpa yang lama).
 *
 * Berkas disimpan di disk **privat** `local` (`storage/app/private/odp-photos/{odp}/…`)
 * dan hanya bisa dibuka lewat rute ber-auth (`odp.photo` untuk web, `api.odps.photo`
 * untuk aplikasi) — jadi partner tak bisa membuka foto ODP milik OLT lain dengan
 * menebak URL.
 *
 * Konversi ke WebP memakai biner **`cwebp`**, bukan GD/Imagick: PHP di server produksi
 * tak memuat kedua ekstensi itu, dan menghindari mendekode gambar tak dipercaya di dalam
 * proses PHP. Kalau `cwebp` tak tersedia (mis. server lain), foto tetap tersimpan apa
 * adanya dalam format aslinya — fitur tidak mati, hanya berkasnya lebih besar.
 */
class OdpPhotoService
{
    public const DISK = 'local';

    /** Ekstensi yang diterima (dipakai juga oleh rule validasi di controller). */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Batas ukuran unggahan dalam KB (harus ≤ upload_max_filesize PHP-FPM). */
    public const MAX_KILOBYTES = 12288;

    /**
     * Aturan validasi unggahan — dipakai identik oleh rute web dan REST API v1
     * (aplikasi Android), supaya batas format/ukuran tak pernah berbeda antar klien.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'photo' => [
                'required',
                'image',
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                'max:'.self::MAX_KILOBYTES,
            ],
        ];
    }

    /**
     * Simpan (atau ganti) foto sebuah ODP. Mengembalikan path relatif yang tersimpan.
     */
    public function store(Odp $odp, UploadedFile $file): string
    {
        $old = $odp->photo_path;

        $converted = $this->toWebp($file->getRealPath());
        $extension = $converted !== null ? 'webp' : $this->extensionOf($file);
        $path = "odp-photos/{$odp->id}/".Str::random(24).'.'.$extension;

        $disk = Storage::disk(self::DISK);
        $disk->put($path, file_get_contents($converted ?? $file->getRealPath()));

        if ($converted !== null) {
            @unlink($converted);
        }

        $odp->forceFill(['photo_path' => $path])->save();

        // Berkas lama dibuang SETELAH yang baru tersimpan — kalau gagal di tengah jalan,
        // ODP tetap punya foto (yang lama) alih-alih kehilangan dua-duanya.
        if ($old !== null && $old !== $path) {
            $disk->delete($old);
        }

        return $path;
    }

    public function delete(Odp $odp): void
    {
        if ($odp->photo_path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($odp->photo_path);
        $odp->forceFill(['photo_path' => null])->save();
    }

    /**
     * Path absolut berkas foto, atau null bila ODP tak punya foto / berkasnya hilang.
     */
    public function absolutePath(Odp $odp): ?string
    {
        $path = $odp->photo_path;
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    /**
     * Token pengubah URL: berubah tiap foto diganti sehingga cache browser/aplikasi
     * ikut menyegar tanpa perlu kolom timestamp tambahan.
     */
    public function cacheToken(Odp $odp): ?string
    {
        return $odp->photo_path === null ? null : substr(md5($odp->photo_path), 0, 8);
    }

    /**
     * URL foto siap pakai, atau null bila ODP belum punya foto.
     *
     * Web memakai rute ber-session (`odp.photo`), aplikasi Android memakai rute Sanctum
     * (`api.odps.photo`) — token bearer tak berlaku di grup web, jadi keduanya dipisah.
     * `?v=` membuat URL berubah tiap foto diganti sehingga cache klien ikut menyegar.
     */
    public function url(Odp $odp, bool $api = false): ?string
    {
        $token = $this->cacheToken($odp);
        if ($token === null) {
            return null;
        }

        return route($api ? 'api.odps.photo' : 'odp.photo', ['odp' => $odp->id, 'v' => $token]);
    }

    /**
     * Konversi gambar ke WebP lewat `cwebp`. Mengembalikan path berkas sementara hasil
     * konversi, atau null bila konversi tak bisa/gagal dilakukan (pemanggil menyimpan asli).
     */
    private function toWebp(string $source): ?string
    {
        $binary = $this->binary();
        if ($binary === null) {
            return null;
        }

        $target = tempnam(sys_get_temp_dir(), 'odp-photo-').'.webp';

        $command = [$binary, '-quiet', '-metadata', 'none', '-q', (string) config('services.cwebp.quality', 82)];

        // Perkecil hanya bila memang lebih besar dari batas — `-resize` juga MEMPERBESAR
        // gambar kecil (foto label ODP 400px jadi 1600px, buram & boros).
        $max = (int) config('services.cwebp.max_dimension', 1600);
        $size = @getimagesize($source);
        if ($max > 0 && is_array($size) && max($size[0], $size[1]) > $max) {
            $command = array_merge($command, $size[0] >= $size[1]
                ? ['-resize', (string) $max, '0']
                : ['-resize', '0', (string) $max]);
        }

        $command = array_merge($command, [$source, '-o', $target]);

        try {
            $process = new Process($command, null, null, null, (float) config('services.cwebp.process_timeout', 30));
            $process->run();

            if ($process->isSuccessful() && is_file($target) && filesize($target) > 0) {
                return $target;
            }

            Log::warning('Konversi foto ODP ke WebP gagal, foto disimpan apa adanya.', [
                'error' => trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ]);
        } catch (ProcessException $e) {
            Log::warning('Biner cwebp tak bisa dijalankan, foto ODP disimpan apa adanya.', [
                'error' => $e->getMessage(),
            ]);
        }

        @unlink($target);

        return null;
    }

    /**
     * Path biner cwebp yang benar-benar bisa dieksekusi, atau null bila tak terpasang.
     */
    private function binary(): ?string
    {
        $configured = (string) config('services.cwebp.binary', 'cwebp');

        if (str_contains($configured, DIRECTORY_SEPARATOR)) {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }

    /**
     * Ekstensi aman untuk fallback simpan-asli (tebak dari MIME, bukan nama berkas kiriman).
     */
    private function extensionOf(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));

        return in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'jpg';
    }
}
