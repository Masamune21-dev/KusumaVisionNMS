<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polling_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->nullable()->constrained('snmp_olts')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->boolean('success')->default(true);
            $table->string('message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
            $table->index(['snmp_olt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polling_events');
    }
};
