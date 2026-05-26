<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartolt_interface_statuses', function (Blueprint $table) {
            $table->string('description')->nullable()->after('link_status');
            $table->unsignedSmallInteger('onu_capacity')->nullable()->after('description');
            $table->unsignedSmallInteger('registered_onu_count')->nullable()->after('onu_capacity');
            $table->unsignedBigInteger('input_bps')->nullable()->after('registered_onu_count');
            $table->unsignedBigInteger('output_bps')->nullable()->after('input_bps');
            $table->unsignedBigInteger('input_pps')->nullable()->after('output_bps');
            $table->unsignedBigInteger('output_pps')->nullable()->after('input_pps');
            $table->decimal('input_throughput_percent', 8, 2)->nullable()->after('output_pps');
            $table->decimal('output_throughput_percent', 8, 2)->nullable()->after('input_throughput_percent');
            $table->decimal('input_average_throughput_percent', 8, 2)->nullable()->after('output_throughput_percent');
            $table->decimal('output_average_throughput_percent', 8, 2)->nullable()->after('input_average_throughput_percent');
            $table->unsignedBigInteger('input_peak_bps')->nullable()->after('output_average_throughput_percent');
            $table->unsignedBigInteger('output_peak_bps')->nullable()->after('input_peak_bps');
            $table->unsignedBigInteger('input_peak_pps')->nullable()->after('output_peak_bps');
            $table->unsignedBigInteger('output_peak_pps')->nullable()->after('input_peak_pps');
            $table->json('gpon_counters')->nullable()->after('output_peak_pps');
        });
    }

    public function down(): void
    {
        Schema::table('smartolt_interface_statuses', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'onu_capacity',
                'registered_onu_count',
                'input_bps',
                'output_bps',
                'input_pps',
                'output_pps',
                'input_throughput_percent',
                'output_throughput_percent',
                'input_average_throughput_percent',
                'output_average_throughput_percent',
                'input_peak_bps',
                'output_peak_bps',
                'input_peak_pps',
                'output_peak_pps',
                'gpon_counters',
            ]);
        });
    }
};
