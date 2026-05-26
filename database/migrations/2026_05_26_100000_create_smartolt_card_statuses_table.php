<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartolt_card_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->unsignedSmallInteger('rack');
            $table->unsignedSmallInteger('shelf');
            $table->unsignedSmallInteger('slot');
            $table->string('cfg_type', 40);
            $table->string('real_type', 40)->nullable();
            $table->unsignedSmallInteger('port_count')->default(0);
            $table->string('hard_ver', 60)->nullable();
            $table->string('soft_ver', 60)->nullable();
            $table->string('status', 40);
            $table->text('raw_line')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['snmp_olt_id', 'rack', 'shelf', 'slot'], 'smartolt_cards_unique_slot');
            $table->index(['snmp_olt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartolt_card_statuses');
    }
};
