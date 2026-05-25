<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->unsignedSmallInteger('poll_interval_minutes')->default(5)->after('polling_enabled');
            $table->unsignedSmallInteger('rx_poll_interval_minutes')->default(5)->after('poll_interval_minutes');
            $table->timestamp('last_rx_polled_at')->nullable()->after('last_polled_at');
        });
    }

    public function down(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropColumn([
                'poll_interval_minutes',
                'rx_poll_interval_minutes',
                'last_rx_polled_at',
            ]);
        });
    }
};
