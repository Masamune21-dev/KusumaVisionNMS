<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snmp_olts', function (Blueprint $table) {
            // Saklar per-OLT: apakah OLT ini ikut backup running-config harian terjadwal.
            // Default off (opt-in) — backup manual selalu bisa lewat tombol.
            $table->boolean('config_backup_enabled')->default(false)->after('alarms_enabled');
        });

        Schema::create('olt_config_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            // Isi running-config disimpan terenkripsi (encrypted cast) karena memuat kredensial
            // (PPPoE, SNMP community). Kosong bila status = failed.
            $table->text('content')->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            // sha256 plaintext untuk dedup: versi identik beruntun tak dibuat baris baru.
            $table->string('sha256', 64)->nullable();
            $table->string('trigger', 16)->default('manual');   // manual | scheduled
            $table->string('status', 16)->default('ok');         // ok | failed
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['snmp_olt_id', 'captured_at']);
            $table->index(['snmp_olt_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_config_backups');

        Schema::table('snmp_olts', function (Blueprint $table) {
            $table->dropColumn('config_backup_enabled');
        });
    }
};
