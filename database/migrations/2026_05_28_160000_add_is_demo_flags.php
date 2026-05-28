<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel yang memerlukan pemisahan data demo vs data nyata di instance yang sama.
     *
     * @var array<int,string>
     */
    private array $tables = [
        'snmp_olts',
        'alarm_events',
        'polling_events',
        'smartolt_onu_registrations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_demo')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('is_demo');
            });
        }
    }
};
