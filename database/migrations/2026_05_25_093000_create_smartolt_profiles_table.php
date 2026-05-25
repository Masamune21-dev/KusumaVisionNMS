<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartolt_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('profile_type', 32);
            $table->string('name', 120);
            $table->unsignedSmallInteger('vlan')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['profile_type', 'name']);
            $table->index(['profile_type', 'is_active']);
        });

        $now = now();
        DB::table('smartolt_profiles')->insert([
            [
                'profile_type' => 'onu_type',
                'name' => 'ALL-ONT',
                'vlan' => null,
                'notes' => 'Default ONU type',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'profile_type' => 'tcont',
                'name' => 'SERVER',
                'vlan' => null,
                'notes' => 'Default T-CONT profile',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'profile_type' => 'vlan',
                'name' => 'ServiceName',
                'vlan' => 100,
                'notes' => 'Default service VLAN profile',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'profile_type' => 'ip',
                'name' => 'INTERNET',
                'vlan' => null,
                'notes' => 'Default static IP profile',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('smartolt_profiles');
    }
};
