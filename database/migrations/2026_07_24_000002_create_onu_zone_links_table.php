<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onu_zone_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('zones')->nullOnDelete();
            // ONU tak punya tabel sendiri — identitas = komposit (snmp_olt_id, slot, port,
            // onu_id), pola sama onu_odp_links/onu_map_pins.
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');
            $table->unsignedSmallInteger('onu_id');
            // Jangkar identitas stabil saat slot/port/onu_id bergeser akibat re-provisioning.
            $table->string('serial_number', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu zona per ONU — assign ulang menggeser link yang sama (updateOrCreate).
            $table->unique(['snmp_olt_id', 'slot', 'port', 'onu_id'], 'onu_zone_links_onu_unique');
            $table->index('zone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_zone_links');
    }
};
