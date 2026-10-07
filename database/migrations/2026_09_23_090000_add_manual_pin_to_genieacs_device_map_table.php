<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyematan manual berbasis IDENTITAS, bukan posisi.
 *
 * Versi pertama menyimpan pasangan manual sebagai posisi (olt/slot/port/onu).
 * Itu berbohong begitu ONU dipindah port — pin tertinggal di posisi lama — atau
 * begitu ONU diganti unit baru, karena pin tetap menunjuk device yang sudah
 * dicopot.
 *
 * Kini operator menyematkan "device ACS ini adalah ONU dengan identitas X"
 * (serial, MAC, atau posisi sebagai jalan terakhir bila ONU tak punya
 * keduanya). Posisinya DITURUNKAN ULANG tiap sinkronisasi, jadi ONU pindah
 * port ikut berpindah sendiri; dan bila identitas itu hilang dari inventori
 * (ONU diganti/dicabut), pin ditandai `manual_stale` — tidak dipakai, tetapi
 * juga tidak dibuang diam-diam, karena justru itu penanda ada ONU yang
 * berganti.
 *
 * `pppoe_username` & `tr069_ip` ikut disimpan supaya pemilih manual bisa
 * menampilkan identitas yang dikenali teknisi di lapangan tanpa memanggil ACS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genieacs_device_map', function (Blueprint $table) {
            $table->string('manual_ref_type', 10)->nullable()->after('match_method');
            $table->string('manual_ref', 64)->nullable()->after('manual_ref_type');
            $table->boolean('manual_stale')->default(false)->after('manual_ref');
            $table->unsignedBigInteger('manual_by')->nullable()->after('manual_stale');
            $table->timestamp('manual_at')->nullable()->after('manual_by');

            $table->string('pppoe_username', 100)->nullable()->after('product_class');
            $table->string('tr069_ip', 45)->nullable()->after('pppoe_username');

            $table->index('pppoe_username');
        });
    }

    public function down(): void
    {
        Schema::table('genieacs_device_map', function (Blueprint $table) {
            $table->dropIndex(['pppoe_username']);
            $table->dropColumn([
                'manual_ref_type', 'manual_ref', 'manual_stale', 'manual_by', 'manual_at',
                'pppoe_username', 'tr069_ip',
            ]);
        });
    }
};
