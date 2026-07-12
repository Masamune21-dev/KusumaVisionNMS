<?php

use App\Services\AlarmEvaluator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan perilaku alarm — singleton, dikelola dari halaman Settings web (admin).
 *
 * `confirm_before_notify` = saklar debounce anti-flap 2 poll:
 *   true  (default) → fault harus MASIH ada di poll berikutnya sebelum notifikasi dikirim
 *                     (perilaku lama; ~2× interval poll, meredam flap).
 *   false            → REALTIME: notifikasi dikirim langsung saat fault pertama terdeteksi.
 *
 * Berlaku global untuk semua OLT & semua kanal (Telegram + FCM), dibaca {@see AlarmEvaluator}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alarm_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('confirm_before_notify')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarm_settings');
    }
};
