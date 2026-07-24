<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            // Zona yang dipilih saat form diisi — disimpan di sini terlepas eksekusi CLI
            // berhasil atau belum, supaya alur "generate sekarang, eksekusi nanti"
            // (executeRegistration) tahu zona mana yang harus dikaitkan ke onu_zone_links
            // begitu eksekusi benar-benar sukses.
            $table->foreignId('zone_id')->nullable()->after('customer_name')->constrained('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
        });
    }
};
