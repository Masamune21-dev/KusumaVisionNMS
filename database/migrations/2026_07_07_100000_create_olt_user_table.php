<?php

use App\Models\Scopes\PartnerOltScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot penugasan OLT ke user role "partner". Sebuah partner hanya boleh melihat/
 * mengedit OLT yang tercatat di sini (ditegakkan {@see PartnerOltScope}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'snmp_olt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_user');
    }
};
