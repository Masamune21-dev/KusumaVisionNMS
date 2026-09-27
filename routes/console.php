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
