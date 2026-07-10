<?php

use App\Http\Controllers\UserController;
use App\Models\Scopes\PartnerOltScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kepemilikan OLT privat oleh partner. Bila `owner_user_id` terisi, OLT itu
 * MILIK partner tsb — hanya dia yang bisa melihat/mengelola (disembunyikan dari
 * admin/operator oleh {@see PartnerOltScope}). Bila NULL, OLT
 * bersifat "global" (dikelola admin/operator seperti sebelumnya).
 *
 * Kolom sengaja TANPA foreign-key constraint agar migrasi tetap kompatibel SQLite
 * (dipakai test). Integritas saat user dihapus ditangani di
 * {@see UserController::destroy()} (owner di-null-kan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropIndex(['owner_user_id']);
            $table->dropColumn('owner_user_id');
        });
    }
};
