<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            // Saklar alarm per-OLT: saat off, AlarmEvaluator berhenti me-raise/clear
            // alarm untuk OLT ini (mute; notifikasi Telegram/FCM ikut senyap).
            $table->boolean('alarms_enabled')->default(true)->after('polling_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropColumn('alarms_enabled');
        });
    }
};
