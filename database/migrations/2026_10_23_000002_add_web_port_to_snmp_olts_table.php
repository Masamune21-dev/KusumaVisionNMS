<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port HTTP antarmuka web OLT — dipakai driver HSGQ (enterprise 50224) untuk aksi ONU lewat API
 * JSON web OLT (login `X-Token`, reboot/ganti nama/simpan). Akun web = `cli_username`/`cli_password`
 * (web & CLI HSGQ berbagi akun). Null = jalur web tak dipakai; family lain tak membacanya.
 * Aditif & nullable → aman ditayangkan sebelum kodenya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->unsignedInteger('web_port')->nullable()->after('cli_port');
        });
    }

    public function down(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropColumn('web_port');
        });
    }
};
