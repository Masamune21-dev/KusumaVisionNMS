<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izinkan IP yang sama dipakai beberapa OLT selama SNMP port-nya berbeda
 * (mis. satu perangkat mengekspos beberapa OLT via port SNMP berbeda).
 * Keunikan pindah dari kolom `ip` tunggal ke pasangan (ip, snmp_port).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropUnique(['ip']);            // snmp_olts_ip_unique
            $table->unique(['ip', 'snmp_port']);   // snmp_olts_ip_snmp_port_unique
        });
    }

    public function down(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropUnique(['ip', 'snmp_port']);
            $table->unique('ip');
        });
    }
};
