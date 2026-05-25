<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartolt_profiles', function (Blueprint $table) {
            $table->dropUnique(['profile_type', 'name']);
            $table->foreignId('snmp_olt_id')->nullable()->after('id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->string('source', 120)->default('manual')->after('name');
            $table->json('params')->nullable()->after('vlan');
            $table->timestamp('last_synced_at')->nullable()->after('is_active');
            $table->unique(['snmp_olt_id', 'profile_type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('smartolt_profiles', function (Blueprint $table) {
            $table->dropUnique(['snmp_olt_id', 'profile_type', 'name']);
            $table->dropConstrainedForeignId('snmp_olt_id');
            $table->dropColumn(['source', 'params', 'last_synced_at']);
            $table->unique(['profile_type', 'name']);
        });
    }
};
