<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            // Warna pin ODP di peta ("#rrggbb"). Null = warna default (amber) — sengaja
            // tanpa backfill supaya ODP lama tampil persis seperti sebelumnya.
            $table->string('color', 7)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
