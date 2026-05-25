<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alarm_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->string('signature');
            $table->string('type');
            $table->string('severity');
            $table->string('status')->default('active');
            $table->string('scope');
            $table->unsignedInteger('slot')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->unsignedInteger('onu_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('message');
            $table->json('meta')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['snmp_olt_id', 'status']);
            $table->index(['snmp_olt_id', 'signature', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarm_events');
    }
};
