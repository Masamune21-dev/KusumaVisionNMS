<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan RX per jam per ONU.
 *
 * `onu_rx_samples` menyimpan tiap pembacaan mentah; dengan ribuan ONU tabel itu
 * tumbuh ke puluhan juta baris (ratusan ribu baris/hari) — sebagian besar ukuran
 * database NMS, dan ikut membengkakkan dump cadangan harian.
 * Isinya pun bukan yang dipakai grafik 7/30 hari: pada rentang itu ratusan titik
 * per jam tidak menambah informasi apa pun, hanya memperberat query dan render.
 *
 * Tabel ini memampatkan satu jam menjadi satu baris (min/avg/max + jumlah
 * sampel), sehingga sampel mentah cukup disimpan beberapa hari untuk grafik
 * 24 jam, sedangkan riwayat panjang tetap utuh di sini dengan biaya ~1/300.
 * min & max ikut disimpan, bukan avg saja: lonjakan redaman sesaat justru
 * gejala yang dicari teknisi, dan rata-rata akan menelannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onu_rx_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snmp_olt_id')->constrained('snmp_olts')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');
            $table->unsignedSmallInteger('onu_id');
            $table->string('serial_number', 64)->nullable();
            $table->timestamp('bucket_hour');
            $table->decimal('rx_min_dbm', 6, 2);
            $table->decimal('rx_avg_dbm', 6, 2);
            $table->decimal('rx_max_dbm', 6, 2);
            $table->unsignedInteger('sample_count');

            // Satu baris per ONU per jam; dipakai juga sebagai kunci upsert
            // supaya agregasi ulang (jam yang sama diproses dua kali) idempoten.
            $table->unique(
                ['snmp_olt_id', 'slot', 'port', 'onu_id', 'bucket_hour'],
                'onu_rx_hourly_bucket_unique'
            );

            // Query grafik: satu ONU dalam rentang waktu.
            $table->index(
                ['snmp_olt_id', 'slot', 'port', 'onu_id', 'bucket_hour'],
                'onu_rx_hourly_lookup_idx'
            );

            // Prune per tanggal.
            $table->index('bucket_hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_rx_hourly');
    }
};
