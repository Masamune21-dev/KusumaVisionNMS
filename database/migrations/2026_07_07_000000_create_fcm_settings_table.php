<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan notifikasi push mobile (FCM) — singleton, dikelola dari halaman
 * Settings web (admin). Menentukan alarm mana yang diteruskan ke aplikasi Android.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('min_severity')->default('major');
            $table->boolean('notify_on_raise')->default(true);
            $table->boolean('notify_on_clear')->default(false);
            $table->json('notify_types')->nullable(); // null = semua tipe
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_settings');
    }
};
