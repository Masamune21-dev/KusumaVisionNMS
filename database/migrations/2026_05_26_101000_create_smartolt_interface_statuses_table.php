<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartolt_interface_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->string('interface', 80);
            $table->string('interface_type', 30);
            $table->unsignedSmallInteger('slot')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('card_type', 40)->nullable();
            $table->string('hybrid_status', 40)->nullable();
            $table->unsignedSmallInteger('native_vlan')->nullable();
            $table->string('negotiation', 40)->nullable();
            $table->unsignedInteger('speed_mbps')->nullable();
            $table->string('duplex', 40)->nullable();
            $table->string('flow_ctrl', 40)->nullable();
            $table->string('admin_status', 40)->nullable();
            $table->string('link_status', 40)->nullable();
            $table->json('tagged_vlans')->nullable();
            $table->string('optical_vendor_name', 120)->nullable();
            $table->string('optical_vendor_pn', 120)->nullable();
            $table->string('optical_vendor_sn', 120)->nullable();
            $table->string('optical_module_type', 80)->nullable();
            $table->unsignedSmallInteger('optical_wavelength_nm')->nullable();
            $table->string('optical_connector', 40)->nullable();
            $table->string('optical_trans_distance', 80)->nullable();
            $table->decimal('rx_power_dbm', 8, 3)->nullable();
            $table->decimal('tx_power_dbm', 8, 3)->nullable();
            $table->decimal('tx_bias_current_ma', 8, 3)->nullable();
            $table->string('laser_rate', 80)->nullable();
            $table->decimal('temperature_c', 8, 3)->nullable();
            $table->decimal('supply_voltage_v', 8, 3)->nullable();
            $table->json('optical_thresholds')->nullable();
            $table->longText('raw_status')->nullable();
            $table->longText('raw_vlan')->nullable();
            $table->longText('raw_optical')->nullable();
            $table->timestamp('status_refreshed_at')->nullable();
            $table->timestamp('vlan_refreshed_at')->nullable();
            $table->timestamp('optical_refreshed_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['snmp_olt_id', 'interface'], 'smartolt_interfaces_unique_name');
            $table->index(['snmp_olt_id', 'interface_type']);
            $table->index(['snmp_olt_id', 'slot', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartolt_interface_statuses');
    }
};
