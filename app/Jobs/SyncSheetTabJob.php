<?php

namespace App\Jobs;

use App\Services\Sheets\SheetsAvailability;
use App\Services\Sheets\SheetsConfigurationException;
use App\Services\Sheets\SheetSyncService;
use App\Services\Sheets\SheetSyncStatus;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Menyalin satu tab ke Google Sheets. "Unik sampai mulai diproses": bila sudah ada yang menunggu, pemicu baru
 * digabung; bila sudah berjalan, pemicu baru tetap antre supaya perubahan yang terjadi saat berjalan tidak hilang.
 */
class SyncSheetTabJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    /** Kunci unik tidak boleh tertinggal selamanya bila worker mati sebelum memproses. */
    public int $uniqueFor = 300;

    /** @return list<int> detik jeda sebelum percobaan ke-2, ke-3, dan ke-4 (kuota Google dipulihkan per menit) */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public readonly string $tab) {}

    public function uniqueId(): string
    {
        return $this->tab;
    }

    public function handle(SheetSyncService $sync): void
    {
        if (! SheetsAvailability::enabled()) {
            return;
        }

        try {
            $sync->sync($this->tab);
        } catch (SheetsConfigurationException $e) {
            // Pengaturan salah tidak membaik dengan diulang; berhenti dan biarkan status menampilkan peringatan.
            $this->fail($e);
        }
    }

    public function failed(Throwable $e): void
    {
        // Setelah semua percobaan habis. Status transient sudah dicatat service; ini menutup kasus job mati di tengah jalan.
        app(SheetSyncStatus::class)->failed($this->tab, $e instanceof SheetsConfigurationException ? 'config' : 'transient');
    }
}
