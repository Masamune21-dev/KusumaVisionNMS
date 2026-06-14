<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onu_rx_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');
            $table->unsignedSmallInteger('onu_id');
            $table->string('serial_number', 64)->nullable();
            $table->decimal('rx_power_dbm', 6, 2);
            $table->timestamp('polled_at')->index();

            // Composite index untuk query tren satu ONU dalam rentang waktu.
            $table->index(['snmp_olt_id', 'slot', 'port', 'onu_id', 'polled_at'], 'onu_rx_samples_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_rx_samples');
    }
};
