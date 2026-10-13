<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda registrasi "Pre-Register ONU": SN diketik manual dan didaftarkan ke OLT sebagai ONU
     * offline sebelum perangkatnya tersambung. Halaman Riwayat Registrasi memakai penanda ini untuk
     * menampilkan status Menunggu ONU / Sudah online.
     */
    public function up(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            $table->boolean('preregistered')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            $table->dropColumn('preregistered');
        });
    }
};
