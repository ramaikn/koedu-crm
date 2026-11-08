<?php

namespace App\Console\Commands;

use App\Services\Sheets\SheetsAvailability;
use App\Services\Sheets\SheetsException;
use App\Services\Sheets\SheetSyncDispatcher;
use App\Services\Sheets\SheetSyncService;
use App\Services\Sheets\SheetTabs;
use Illuminate\Console\Command;

/** Menyalin data ke Google Sheets sekarang. Pemicu rutinnya otomatis (perubahan data dan n8n); ini untuk penyalinan pertama atau uji. */
class SheetsSyncCommand extends Command
{
    protected $signature = 'sheets:sync {tab? : jadwal, client, atau paket. Kosong = semua} {--now : Jalankan langsung dan tampilkan hasilnya, tanpa antrean}';

    protected $description = 'Salin data ke Google Sheets';

    public function handle(SheetSyncService $sync, SheetSyncDispatcher $dispatcher): int
    {
        if (! SheetsAvailability::enabled()) {
            $this->warn('Google Sheets belum aktif. Jalankan "php artisan sheets:check" untuk melihat yang kurang.');

            return self::FAILURE;
        }

        $tabs = $this->argument('tab') ? [$this->argument('tab')] : SheetTabs::keys();
        if (array_diff($tabs, SheetTabs::keys()) !== []) {
            $this->error('Tab tidak dikenal. Pilihan: '.implode(', ', SheetTabs::keys()));

            return self::FAILURE;
        }

        if (! $this->option('now')) {
            $dispatcher->touch(...$tabs);
            $this->info('Dijadwalkan lewat antrean (jeda sekitar 10 detik): '.implode(', ', $tabs));

            return self::SUCCESS;
        }

        foreach ($tabs as $tab) {
            try {
                $this->info("{$tab}: {$sync->sync($tab)} baris disalin.");
            } catch (SheetsException $e) {
                $this->error("{$tab}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
