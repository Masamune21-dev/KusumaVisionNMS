<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartolt_card_statuses', function (Blueprint $table) {
            // Per-board processor load from zxAnCardTable SNMP (matches `show processor`).
            $table->unsignedSmallInteger('cpu_load')->nullable()->after('status');   // CPU usage %
            $table->unsignedSmallInteger('mem_load')->nullable()->after('cpu_load'); // memory usage %
            $table->unsignedInteger('phy_mem_mb')->nullable()->after('mem_load');    // physical memory (MB)
        });
    }

    public function down(): void
    {
        Schema::table('smartolt_card_statuses', function (Blueprint $table) {
            $table->dropColumn(['cpu_load', 'mem_load', 'phy_mem_mb']);
        });
    }
};
