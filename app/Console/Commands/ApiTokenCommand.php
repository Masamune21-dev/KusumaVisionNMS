<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Menerbitkan personal access token API tanpa lewat endpoint login —
 * berguna untuk integrasi server-ke-server (web aplikasi lain / cron / backend).
 *
 * Contoh:
 *   php artisan api:token admin@bmkv.net
 *   php artisan api:token admin@bmkv.net --name="Billing App"
 */
class ApiTokenCommand extends Command
{
    protected $signature = 'api:token {email : Email user pemilik token} {--name=cli-token : Nama/label token}';

    protected $description = 'Terbitkan API access token (Sanctum) untuk seorang user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("User dengan email {$email} tidak ditemukan.");

            return self::FAILURE;
        }

        $token = $user->createToken((string) $this->option('name'));

        $this->info('Token berhasil dibuat. Simpan baik-baik — hanya ditampilkan sekali ini saja:');
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line("Pakai pada header:  Authorization: Bearer {$token->plainTextToken}");

        return self::SUCCESS;
    }
}
