<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pin terkunci = tak bisa digeser di peta. Default true supaya baris lama
        // mempertahankan perilaku sebelumnya (posisi pin memang tak bisa dipindah).
        Schema::table('onu_map_pins', function (Blueprint $table) {
            $table->boolean('locked')->default(true)->after('longitude');
        });

        Schema::table('odps', function (Blueprint $table) {
            $table->boolean('locked')->default(true)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('onu_map_pins', function (Blueprint $table) {
            $table->dropColumn('locked');
        });

        Schema::table('odps', function (Blueprint $table) {
            $table->dropColumn('locked');
        });
    }
};
