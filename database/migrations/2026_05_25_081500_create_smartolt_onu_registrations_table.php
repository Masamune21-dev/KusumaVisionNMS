<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartolt_onu_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->string('serial_number', 64);
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');
            $table->unsignedInteger('onu_id');
            $table->string('pon_port', 120);
            $table->string('oid_index')->nullable();
            $table->string('customer_name');
            $table->string('onu_type', 120)->default('ALL-ONT');
            $table->string('tcont_profile', 120)->default('SERVER');
            $table->unsignedSmallInteger('vlan');
            $table->string('vlan_profile', 120)->nullable();
            $table->string('service_name', 120)->default('ServiceName');
            $table->enum('wan_mode', ['pppoe', 'dhcp', 'static'])->default('pppoe');
            $table->string('pppoe_username', 120)->nullable();
            $table->text('pppoe_password')->nullable();
            $table->string('ip_profile', 120)->nullable();
            $table->ipAddress('static_ip')->nullable();
            $table->string('static_netmask', 45)->nullable();
            $table->longText('cli_script');
            $table->string('status', 32)->default('generated');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['snmp_olt_id', 'status']);
            $table->index(['serial_number', 'created_at']);
            $table->index(['snmp_olt_id', 'slot', 'port', 'onu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartolt_onu_registrations');
    }
};
