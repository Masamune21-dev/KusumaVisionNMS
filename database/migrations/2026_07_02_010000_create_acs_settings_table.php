<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endpoint ACS / TR069 yang bisa diedit dari halaman Pengaturan (singleton).
 * Password disimpan terenkripsi di kolom teks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acs_settings', function (Blueprint $table) {
            $table->id();
            $table->string('url')->nullable();
            $table->string('username', 100)->nullable();
            $table->text('password')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acs_settings');
    }
};
