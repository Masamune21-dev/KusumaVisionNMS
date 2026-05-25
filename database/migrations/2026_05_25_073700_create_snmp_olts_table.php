<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snmp_olts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('vendor', 100)->nullable();
            $table->ipAddress('ip')->unique();
            $table->unsignedSmallInteger('snmp_port')->default(161);
            $table->text('snmp_read_community');
            $table->text('snmp_write_community')->nullable();
            $table->enum('snmp_version', ['v1', 'v2c', 'v3'])->default('v2c');
            $table->enum('cli_transport', ['telnet', 'ssh'])->nullable();
            $table->unsignedSmallInteger('cli_port')->nullable();
            $table->string('cli_username', 100)->nullable();
            $table->text('cli_password')->nullable();
            $table->json('last_test_result')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();

            $table->index(['vendor', 'ip']);
            $table->index('last_tested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snmp_olts');
    }
};
