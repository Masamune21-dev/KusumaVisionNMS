<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odps', function (Blueprint $table) {
            $table->id();
            // ODP fisik selalu di bawah satu OLT/PON → di-scope per-OLT (PartnerOltScope).
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->string('name', 128);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('snmp_olt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odps');
    }
};
