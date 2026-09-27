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
Schedule::command('olts:backup-config')->dailyAt('02:30')->withoutOverlapping();
