<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kredensial NBI GenieACS (port 7557) yang dipakai NMS untuk membaca katalog
 * device TR-069. Singleton (satu baris), password disimpan terenkripsi.
 *
 * Sengaja TERPISAH dari `acs_settings`: tabel itu menyimpan URL CWMP (7547) yang
 * ditanam ke ONU lewat fitur TR069 Massal, sedangkan tabel ini menyimpan alamat
 * NBI yang dipakai dashboard. Keduanya menunjuk server yang sama tetapi perannya
 * berbeda, jadi tidak boleh digabung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genieacs_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->unsignedInteger('port')->default(7557);
            $table->string('username', 100)->nullable();
            $table->text('password')->nullable();
            $table->string('role', 50)->nullable();
            $table->boolean('is_connected')->default(false);
            $table->timestamp('last_test_at')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genieacs_credentials');
    }
};
