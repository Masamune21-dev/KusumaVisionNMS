<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            $table->boolean('tr069_enabled')->default(false)->after('static_netmask');
            $table->string('acs_url')->nullable()->after('tr069_enabled');
            $table->string('acs_username', 120)->nullable()->after('acs_url');
            $table->text('acs_password')->nullable()->after('acs_username');
            $table->boolean('remote_ont_enabled')->default(false)->after('acs_password');
            $table->unsignedSmallInteger('remote_ont_id')->nullable()->after('remote_ont_enabled');
            $table->string('remote_ont_mode', 32)->nullable()->after('remote_ont_id');
            $table->string('remote_ont_protocol', 32)->nullable()->after('remote_ont_mode');
        });
    }

    public function down(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'tr069_enabled',
                'acs_url',
                'acs_username',
                'acs_password',
                'remote_ont_enabled',
                'remote_ont_id',
                'remote_ont_mode',
                'remote_ont_protocol',
            ]);
        });
    }
};
