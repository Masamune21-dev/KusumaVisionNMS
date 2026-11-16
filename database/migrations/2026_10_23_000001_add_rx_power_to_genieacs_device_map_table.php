<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redaman ONU menurut ONU itu sendiri, dilaporkan lewat TR-069
 * (`VirtualParameters.RXPower`), sebagai CADANGAN untuk ONU yang DDM-nya tak
 * dilaporkan OLT — mis. OLT-HIOSO-PEKALONGAN PON 2–4, ONU tertahan di
 * `CtcInfo` sehingga OLT tak pernah menarik data optiknya.
 *
 * `rx_power_at` = kapan ACS terakhir menyegarkan nilai itu (`_timestamp`
 * parameter, disegarkan tiap inform), BUKAN kapan sinkronisasi berjalan.
 * Pemakainya wajib menolak nilai yang lebih tua dari putusnya link ONU — lihat
 * {@see \App\Services\Genieacs\GenieacsRxReadings}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genieacs_device_map', function (Blueprint $table) {
            $table->decimal('rx_power_dbm', 5, 2)->nullable()->after('tr069_ip');
            $table->timestamp('rx_power_at')->nullable()->after('rx_power_dbm');
        });
    }

    public function down(): void
    {
        Schema::table('genieacs_device_map', function (Blueprint $table) {
            $table->dropColumn(['rx_power_dbm', 'rx_power_at']);
        });
    }
};
