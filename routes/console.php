<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tugas terjadwal Laravel, dijalankan container "scheduler" (php artisan schedule:work).
| Notifikasi WA dan rekonsiliasi Sheets dipicu n8n, bukan dari sini.
*/

// Lewat tengah malam supaya paket yang berakhir kemarin langsung berubah status di awal hari.
Schedule::command('enrollments:expire')->dailyAt('00:05')->withoutOverlapping();
