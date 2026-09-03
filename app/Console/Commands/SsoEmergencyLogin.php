<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Jalan masuk darurat ketika IdP tidak bisa dihubungi.
 *
 * Karena seluruh login app ini bergantung pada sso.kusumavision.net, matinya IdP
 * berarti tidak seorang pun bisa masuk. Perintah ini menerbitkan satu tautan
 * login bertanda tangan yang berlaku 5 menit dan sekali pakai.
 *
 * Ia TIDAK menambah permukaan serangan dari internet: satu-satunya cara
 * menerbitkan tautannya adalah punya akses shell ke server ini — yang artinya
 * penyerang sudah lebih dari sekadar bisa login.
 */
class SsoEmergencyLogin extends Command
{
    protected $signature = 'sso:emergency-login {email : Email pengguna}';

    protected $description = 'Terbitkan tautan login darurat (dipakai saat IdP mati)';

    public function handle(): int
    {
        $user = User::whereRaw('lower(email) = ?', [Str::lower($this->argument('email'))])->first();

        if ($user === null) {
            $this->error('Pengguna tidak ditemukan di database app ini.');

            return self::FAILURE;
        }

        // Nonce membuat tautan sekali pakai: URL bertanda tangan saja masih bisa
        // dipakai berulang selama masa berlakunya.
        $nonce = Str::random(48);
        Cache::put($this->cacheKey($user->id), Hash::make($nonce), now()->addMinutes(5));

        $url = URL::temporarySignedRoute('sso.emergency', now()->addMinutes(5), [
            'user' => $user->id,
            'nonce' => $nonce,
        ]);

        $this->newLine();
        $this->warn("Tautan login darurat untuk {$user->email} (berlaku 5 menit, sekali pakai):");
        $this->line($url);
        $this->newLine();
        $this->line('Setelah IdP pulih, logout lalu login seperti biasa.');

        return self::SUCCESS;
    }

    public static function cacheKey(int|string $userId): string
    {
        return "sso:emergency:{$userId}";
    }
}
