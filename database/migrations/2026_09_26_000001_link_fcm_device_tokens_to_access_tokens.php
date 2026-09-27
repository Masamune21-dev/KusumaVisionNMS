<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kaitkan token push FCM dengan sesi login aplikasi (token Sanctum) yang
 * mendaftarkannya.
 *
 * Tanpa kaitan ini server tak tahu ponsel mana yang sudah keluar: logout,
 * sesi kedaluwarsa, maupun sesi yang dicabut admin tak pernah menghentikan
 * push alarm ke ponsel itu. Dengan `cascadeOnDelete`, menghapus sesi (logout,
 * `sanctum:prune-expired`) ikut menghapus token push-nya di tingkat database.
 *
 * Nullable: baris lama belum punya kaitan dan tetap dikirimi sampai aplikasi
 * mendaftarkan ulang tokennya (terjadi tiap aplikasi dibuka dalam keadaan login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fcm_device_tokens', function (Blueprint $table) {
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->after('user_id')
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fcm_device_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
        });
    }
};
