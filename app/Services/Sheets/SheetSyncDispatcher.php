<?php

namespace App\Services\Sheets;

use App\Jobs\SyncSheetTabJob;

/** Menjadwalkan sinkron tab lewat antrean. Perubahan beruntun digabung: satu tab hanya punya satu sinkron yang menunggu. */
final class SheetSyncDispatcher
{
    /** Jeda sebelum sinkron jalan, supaya beberapa perubahan beruntun (mis. satu form) cukup satu penulisan. */
    private const DEBOUNCE_SECONDS = 10;

    /** @return list<string> tab yang dijadwalkan; kosong bila sinkron tidak aktif */
    public function touch(string ...$tabs): array
    {
        if (! SheetsAvailability::enabled()) {
            return [];
        }

        $scheduled = array_values(array_unique($tabs));
        foreach ($scheduled as $tab) {
            SyncSheetTabJob::dispatch($tab)->onQueue('sheets')->delay(now()->addSeconds(self::DEBOUNCE_SECONDS))->afterCommit();
        }

        return $scheduled;
    }

    /** Pencocokan penuh semua tab, tanpa jeda. */
    public function all(): array
    {
        if (! SheetsAvailability::enabled()) {
            return [];
        }

        $keys = SheetTabs::keys();
        foreach ($keys as $tab) {
            SyncSheetTabJob::dispatch($tab)->onQueue('sheets');
        }

        return $keys;
    }
}
