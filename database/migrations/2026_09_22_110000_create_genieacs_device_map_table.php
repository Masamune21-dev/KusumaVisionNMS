<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan device GenieACS (TR-069) ke posisi ONU di NMS.
 *
 * ONU sendiri TIDAK punya tabel di NMS — keadaannya hidup di JSON
 * `snmp_olts.last_test_result`. Tabel ini hanya menyimpan HASIL pencocokan
 * supaya tabel ONU cukup melakukan satu join dan tidak pernah memanggil ACS
 * saat merender halaman.
 *
 * `match_method` mencatat bagaimana pasangan ditemukan:
 *   serial — serial ONU sama persis di kedua sisi (paling kuat)
 *   mac    — MAC PON GenieACS cocok dengan MAC ONU dari OLT, toleransi ±1
 *            (MAC PON dan MAC LAN pada perangkat yang sama berselisih satu)
 *   manual — ditetapkan operator; SELALU menang atas hasil otomatis
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genieacs_device_map', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique();
            $table->string('serial_number', 64)->nullable();
            // MAC PON ternormalisasi: 12 digit heksadesimal huruf kecil, tanpa pemisah.
            $table->string('pon_mac', 12)->nullable();
            $table->string('manufacturer', 64)->nullable();
            $table->string('product_class', 64)->nullable();

            $table->unsignedBigInteger('snmp_olt_id')->nullable();
            $table->unsignedInteger('slot')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->unsignedInteger('onu_id')->nullable();

            $table->string('match_method', 16)->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('last_inform_at')->nullable();
            $table->timestamps();

            $table->index('serial_number');
            $table->index('pon_mac');
            $table->index(['snmp_olt_id', 'slot', 'port', 'onu_id'], 'genieacs_device_map_onu_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genieacs_device_map');
    }
};
