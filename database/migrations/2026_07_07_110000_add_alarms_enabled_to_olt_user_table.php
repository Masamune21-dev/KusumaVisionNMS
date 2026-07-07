<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar alarm per-partner-per-OLT. Tiap baris penugasan (partner ↔ OLT) menyimpan
 * apakah partner tsb ingin menerima alarm OLT itu lewat webhook/FCM-nya sendiri.
 * Independen dari kolom `snmp_olts.alarms_enabled` (saklar admin/operator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olt_user', function (Blueprint $table) {
            $table->boolean('alarms_enabled')->default(true)->after('snmp_olt_id');
        });
    }

    public function down(): void
    {
        Schema::table('olt_user', function (Blueprint $table) {
            $table->dropColumn('alarms_enabled');
        });
    }
};
