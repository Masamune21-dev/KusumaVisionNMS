<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ringkasan per OLT: mode kontrol, suhu lingkungan, ambang, dan percent tiap level.
        // Dipisah dari tabel fan supaya satu OLT hanya punya satu baris ringkasan.
        Schema::create('smartolt_fan_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->string('source', 10)->default('snmp');
            $table->string('mode', 20)->default('unknown');
            $table->unsignedTinyInteger('mode_code')->nullable();
            $table->unsignedSmallInteger('temp_c')->nullable();
            $table->unsignedTinyInteger('min_level')->nullable();
            $table->json('thresholds')->nullable();
            $table->json('percents')->nullable();
            $table->text('raw_output')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique('snmp_olt_id');
        });

        Schema::create('smartolt_fan_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->unsignedSmallInteger('rack')->default(1);
            $table->unsignedSmallInteger('shelf')->default(1);
            $table->unsignedSmallInteger('fan_index');
            $table->string('fan_name', 40)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->unsignedTinyInteger('admin_status')->nullable();
            $table->unsignedTinyInteger('oper_status')->nullable();
            $table->unsignedTinyInteger('online_status')->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedInteger('speed_rpm')->nullable();
            $table->unsignedTinyInteger('speed_percent')->nullable();
            $table->text('raw_line')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['snmp_olt_id', 'rack', 'shelf', 'fan_index'], 'smartolt_fans_unique_unit');
            $table->index(['snmp_olt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartolt_fan_statuses');
        Schema::dropIfExists('smartolt_fan_summaries');
    }
};
