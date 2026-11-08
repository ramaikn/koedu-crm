<?php

namespace App\Services\Sheets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Menyalin satu tab dari database ke Google Sheets (menggantikan seluruh isi) dan mencatat statusnya. */
final class SheetSyncService
{
    public function __construct(private readonly SheetSyncStatus $status) {}

    /**
     * @return int jumlah baris data yang ditulis
     *
     * @throws SheetsConfigurationException perlu diperbaiki manusia, jangan dicoba ulang
     * @throws SheetsTransientException sementara, layak dicoba ulang
     */
    public function sync(string $key): int
    {
        $tab = SheetTabs::make($key);

        try {
            $rows = $tab->rows();
            // Klien dibuat di sini: bila kunci atau ID salah, kegagalannya tercatat sebagai status tab, bukan error saat boot.
            app(GoogleSheetsClient::class)->replaceTab($tab->title(), $this->note(count($rows)), $tab->headers(), $rows);
        } catch (SheetsConfigurationException $e) {
            $this->status->failed($key, 'config');
            Log::error('Sinkron Google Sheets gagal: pengaturan', ['tab' => $key, 'alasan' => $e->getMessage()]);

            throw $e;
        } catch (SheetsTransientException $e) {
            $this->status->failed($key, 'transient');
            Log::warning('Sinkron Google Sheets gagal sementara', ['tab' => $key, 'alasan' => $e->getMessage()]);

            throw $e;
        }

        // Query builder langsung, bukan model: tidak memicu observer (yang akan menjadwalkan sinkron lagi) dan tidak mengubah updated_at.
        if (($ids = $tab->syncedConsultationIds()) !== []) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('consultations')->whereIn('id', $chunk)->update(['sheet_synced_at' => now()]);
            }
        }

        $this->status->succeeded($key, count($rows));
        Log::info('Sinkron Google Sheets selesai', ['tab' => $key, 'baris' => count($rows)]);

        return count($rows);
    }

    private function note(int $rows): string
    {
        $tz = config('app.timezone');

        return config('app.name').': salinan otomatis, jangan diedit (isi akan ditimpa). Disalin '.now($tz)->format('Y-m-d H:i').' '.$tz.", {$rows} baris.";
    }
}
