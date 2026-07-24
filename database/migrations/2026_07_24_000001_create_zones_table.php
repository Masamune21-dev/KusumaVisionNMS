<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            // Selalu MAYUSKUL (dinormalisasi di model Zone, bukan DB constraint) — label
            // geografis sederhana, global (BUKAN per-OLT seperti Odp — dikelola admin saja
            // via Settings, lihat middleware role:admin di routes/web.php).
            $table->string('name', 120)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
