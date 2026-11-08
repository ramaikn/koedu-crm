<?php

namespace App\Console\Commands;

use App\Services\Sheets\GoogleSheetsClient;
use App\Services\Sheets\SheetsConfigurationException;
use App\Services\Sheets\SheetsException;
use App\Services\Sheets\SheetTabs;
use Illuminate\Console\Command;

/**
 * Memeriksa penyiapan Google Sheets langkah demi langkah dan menunjukkan apa yang perlu diperbaiki.
 * Kunci tidak ditampilkan; email akun layanan boleh karena itu alamat yang perlu diberi akses ke Sheet.
 */
class SheetsCheckCommand extends Command
{
    protected $signature = 'sheets:check';

    protected $description = 'Periksa pengaturan Google Sheets (kunci, akses ke spreadsheet, tab)';

    public function handle(): int
    {
        $this->line('GOOGLE_SHEETS_ENABLED     : '.(config('koedu.sheets.enabled') ? 'menyala' : 'mati'));
        $this->line('GOOGLE_SHEETS_SPREADSHEET_ID : '.(filled(config('koedu.sheets.spreadsheet_id')) ? 'terisi' : 'KOSONG'));
        $path = config('koedu.sheets.credentials_path');
        $this->line('File kunci                : '.($path && is_file($path) ? 'ada' : 'TIDAK ADA di '.($path ?: '(path kosong)')));

        if (blank(config('koedu.sheets.spreadsheet_id')) || ! $path || ! is_file($path)) {
            $this->newLine();
            $this->error('Penyiapan belum lengkap. Ikuti langkah di docs/agent/knowledge/google-sheets.md (bagian "Menyiapkan").');

            return self::FAILURE;
        }

        try {
            $client = app(GoogleSheetsClient::class);
            $this->line("Akun layanan             : {$client->serviceAccountEmail()}");
            $info = $client->inspect();
        } catch (SheetsConfigurationException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (SheetsException $e) {
            $this->newLine();
            $this->error('Gagal sementara: '.$e->getMessage().' Coba lagi beberapa saat lagi.');

            return self::FAILURE;
        }

        $this->line("Spreadsheet              : {$info['title']}");
        $this->newLine();
        $this->table(['Tab', 'Judul di Sheet', 'Status'], array_map(function (string $key) use ($info) {
            $title = SheetTabs::make($key)->title();

            return [$key, $title, isset($info['tabs'][$title]) ? 'sudah ada' : 'akan dibuat otomatis saat sinkron pertama'];
        }, SheetTabs::keys()));

        $this->info('Akses berhasil. Jalankan "php artisan sheets:sync --now" untuk menyalin data pertama kali.');

        return self::SUCCESS;
    }
}
