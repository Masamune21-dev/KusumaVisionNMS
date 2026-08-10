<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Label port PON sisi-NMS untuk OLT non-ZTE (C-Data, HiOSO, HsAirPo).
 *
 * ZTE menyimpan deskripsi port di perangkat (CLI `interface … / description …`), sedangkan
 * family lain tak punya perintah setara yang terverifikasi. Label di sini murni milik NMS —
 * tak pernah ditulis ke OLT — dan aman dari scan/poll karena tidak ikut `last_test_result`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_port_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');
            $table->string('label', 64);
            $table->timestamps();

            $table->unique(['snmp_olt_id', 'slot', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_port_labels');
    }
};
