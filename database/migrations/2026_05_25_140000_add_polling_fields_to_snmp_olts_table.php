<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->boolean('polling_enabled')->default(true)->after('snmp_version');
            $table->timestamp('last_polled_at')->nullable()->after('last_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropColumn(['polling_enabled', 'last_polled_at']);
        });
    }
};
