<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // zone_id dibuat NOT NULL secara tak sengaja di migrasi awal — berbenturan dengan
        // FK action nullOnDelete() (ON DELETE SET NULL gagal tanpa kolom nullable), bikin
        // hapus zona tanpa reassign selalu error alih-alih meng-orphan link jadi "Sin zona".
        Schema::table('onu_zone_links', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('onu_zone_links', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable(false)->change();
        });
    }
};
