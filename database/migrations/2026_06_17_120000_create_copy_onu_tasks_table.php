<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copy_onu_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('src_slot');
            $table->unsignedSmallInteger('src_port');
            $table->unsignedSmallInteger('dst_slot');
            $table->unsignedSmallInteger('dst_port');
            $table->boolean('execute')->default(false);
            $table->json('onu_ids');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('executed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 32)->default('queued');
            $table->json('items')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['snmp_olt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copy_onu_tasks');
    }
};
