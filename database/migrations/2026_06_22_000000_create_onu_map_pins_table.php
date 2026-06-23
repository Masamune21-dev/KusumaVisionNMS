<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onu_map_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');
            $table->unsignedSmallInteger('onu_id');
            // Jangkar identitas stabil saat slot/port/onu_id bergeser akibat re-provisioning.
            $table->string('serial_number', 64)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // Override nama pelanggan; null = pakai nama/deskripsi ONU live dari cache.
            $table->string('customer_name', 191)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu pin per ONU — simpan ulang menggeser titik yang sama (updateOrCreate).
            $table->unique(['snmp_olt_id', 'slot', 'port', 'onu_id'], 'onu_map_pins_onu_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_map_pins');
    }
};
