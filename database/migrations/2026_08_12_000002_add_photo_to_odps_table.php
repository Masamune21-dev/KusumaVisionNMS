<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            // Path relatif foto ODP di disk privat `local` (mis. "odp-photos/12/ab12cd34.webp").
            // Satu foto per ODP — upload baru menimpa yang lama. Berkasnya TIDAK dilayani
            // langsung oleh nginx; hanya lewat rute ber-auth (lihat OdpPhotoService).
            $table->string('photo_path', 255)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
