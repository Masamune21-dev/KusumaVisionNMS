<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto save konfigurasi OLT (`write`/`save` ke memori OLT) — saklar per-OLT.
 *
 * - `config_autosave_enabled`: saklar, bawaan mati.
 * - `config_changed_at` / `config_dirty_since`: perubahan terakhir lewat NMS, dan perubahan
 *   PERTAMA yang belum tersimpan (null = bersih). Ditulis untuk semua OLT, saklar nyala atau tidak.
 * - `config_saved_at`: save terakhir yang berhasil (manual atau otomatis) — waktu MULAI save-nya.
 * - `config_autosave_*`: hasil percobaan auto save terakhir (ok / failed / deferred).
 *
 * Aditif & nullable → aman ditayangkan sebelum kodenya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->boolean('config_autosave_enabled')->default(false)->after('config_backup_enabled');
            $table->timestamp('config_changed_at')->nullable();
            $table->timestamp('config_dirty_since')->nullable();
            $table->timestamp('config_saved_at')->nullable();
            $table->timestamp('config_autosave_at')->nullable();
            $table->string('config_autosave_status', 16)->nullable();
            $table->string('config_autosave_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropColumn([
                'config_autosave_enabled',
                'config_changed_at',
                'config_dirty_since',
                'config_saved_at',
                'config_autosave_at',
                'config_autosave_status',
                'config_autosave_error',
            ]);
        });
    }
};
