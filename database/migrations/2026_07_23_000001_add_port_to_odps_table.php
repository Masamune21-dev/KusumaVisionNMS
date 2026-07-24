<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            // ODP fisik selalu di bawah satu PON port; nullable karena ODP kosong
            // (belum ada ONU) boleh belum diketahui portnya.
            $table->unsignedSmallInteger('slot')->nullable()->after('name');
            $table->unsignedSmallInteger('port')->nullable()->after('slot');
        });

        // Backfill ODP lama: ONU dalam satu ODP pasti di port yang sama, jadi ambil
        // slot/port dari salah satu link ONU-nya (link pertama) sebagai port ODP.
        DB::table('odps')->orderBy('id')->each(function ($odp) {
            $link = DB::table('onu_odp_links')
                ->where('odp_id', $odp->id)
                ->orderBy('id')
                ->first(['slot', 'port']);

            if ($link !== null) {
                DB::table('odps')
                    ->where('id', $odp->id)
                    ->update(['slot' => $link->slot, 'port' => $link->port]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('odps', function (Blueprint $table) {
            $table->dropColumn(['slot', 'port']);
        });
    }
};
