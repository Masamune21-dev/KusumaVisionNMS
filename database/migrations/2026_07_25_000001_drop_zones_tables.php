<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur Zones dibatalkan — tidak cocok dengan model kami (zona pelanggan sudah
 * dibawa di deskripsi ONU gaya SmartOLT, di-parse via parseOnuDescription).
 * Migrasi create-zones sudah dihapus dari repo (revert), jadi instalasi baru
 * tak pernah membuat tabel ini; migrasi ini membersihkan server yang terlanjur
 * menjalankannya. Guarded supaya no-op di sqlite test / fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('smartolt_onu_registrations', 'zone_id')) {
            Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
                $table->dropColumn('zone_id');
            });
        }

        Schema::dropIfExists('onu_zone_links');
        Schema::dropIfExists('zones');
    }

    public function down(): void
    {
        // Fitur Zones dihapus permanen — tak ada rollback.
    }
};
