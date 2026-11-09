<?php

namespace App\Console\Commands;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Menandai paket aktif yang masa aktifnya sudah lewat sebagai kedaluwarsa. Dijalankan scheduler tiap malam.
 * Aman dijalankan berulang: hanya paket berstatus aktif yang disentuh.
 */
class ExpireEnrollmentsCommand extends Command
{
    protected $signature = 'enrollments:expire';

    protected $description = 'Ubah paket client yang lewat tanggal berakhir menjadi kedaluwarsa';

    public function handle(): int
    {
        // Tanggal mengikuti zona waktu kantor (config app.timezone). Paket masih berlaku sepanjang hari ends_on.
        $today = now()->toDateString();
        $count = 0;

        // Diubah per model, bukan update massal, supaya observer Google Sheets ikut terpicu.
        Enrollment::query()
            ->where('status', EnrollmentStatus::Active)
            ->whereDate('ends_on', '<', $today)
            ->chunkById(100, function ($enrollments) use (&$count) {
                foreach ($enrollments as $enrollment) {
                    $enrollment->update(['status' => EnrollmentStatus::Expired]);
                    $count++;
                }
            });

        if ($count > 0) {
            Log::info('Paket client kedaluwarsa otomatis', ['count' => $count]);
        }
        $this->info("{$count} paket ditandai kedaluwarsa.");

        return self::SUCCESS;
    }
}
