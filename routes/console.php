<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('olts:poll')->everyMinute()->withoutOverlapping();
// Agregasi wajib mendahului prune: sampel mentah baru boleh dibuang setelah
// jamnya terangkum, kalau tidak riwayat panjangnya hilang permanen.
Schedule::command('optical:aggregate-rx')->hourlyAt(5)->withoutOverlapping();
Schedule::command('optical:prune-rx')->dailyAt('03:15')->withoutOverlapping();

// Sesi aplikasi yang kedaluwarsa (sanctum.expiration) dibuang — token push FCM
// yang terkait ikut terhapus lewat FK cascade, jadi ponselnya berhenti dikirimi alarm.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:40');
Schedule::command('olts:backup-config')->dailyAt('02:30')->withoutOverlapping();
// Auto save config OLT (saklar per-OLT). Tiap 5 menit: OLT yang diubah lewat NMS dan sudah tenang
// 10 menit (paling lambat 60 menit). Harian 02:00: semua OLT ber-saklar nyala, jaring pengaman
// perubahan lewat telnet — sebelum backup 02:30 supaya keduanya membaca config yang sama.
// runInBackground: write ZTE bisa 30–120 dtk per OLT, jangan menahan jadwal lain.
Schedule::command('olts:autosave-config')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('olts:autosave-config --all')->dailyAt('02:00')->withoutOverlapping(180)->runInBackground();
// Pencocokan device GenieACS ke posisi ONU. Sengaja 15 menit, BUKAN tiap menit:
// peta ONU tidak berubah secepat itu dan server ini sudah sibuk oleh poller SNMP.
// Satu jalannya menarik ±688 KB dari NBI (projection ringan) dan selesai <1 detik.
// Tanpa NBI di Pengaturan perintah ini selesai diam-diam (bukan kegagalan).
Schedule::command('genieacs:match-onu')->everyFifteenMinutes()->withoutOverlapping(10)->runInBackground();
