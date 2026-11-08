<?php

namespace App\Services\Sheets;

use Illuminate\Support\Facades\Cache;

/**
 * Status sinkron terakhir per tab, untuk ditampilkan di dashboard. Disimpan di cache: hanya info status,
 * jadi kehilangannya tidak berbahaya (terisi lagi pada sinkron berikutnya).
 */
final class SheetSyncStatus
{
    private const TTL_DAYS = 30;

    public function succeeded(string $tab, int $rows): void
    {
        Cache::put($this->key($tab), ['synced_at' => now()->toIso8601String(), 'rows' => $rows, 'error' => null], now()->addDays(self::TTL_DAYS));
    }

    /** @param string $reason salah satu: "config" (perlu diperbaiki manusia) atau "transient" (sementara, akan dicoba lagi) */
    public function failed(string $tab, string $reason): void
    {
        $previous = Cache::get($this->key($tab), []);
        Cache::put($this->key($tab), [...$previous, 'error' => $reason, 'failed_at' => now()->toIso8601String()], now()->addDays(self::TTL_DAYS));
    }

    /** @return list<array{tab: string, title: string, synced_at: ?string, rows: ?int, error: ?string}> */
    public function summary(): array
    {
        return array_map(function (string $key) {
            $status = Cache::get($this->key($key), []);

            return [
                'tab' => $key,
                'title' => SheetTabs::make($key)->title(),
                'synced_at' => $status['synced_at'] ?? null,
                'rows' => $status['rows'] ?? null,
                'error' => $status['error'] ?? null,
            ];
        }, SheetTabs::keys());
    }

    private function key(string $tab): string
    {
        return "sheets:status:{$tab}";
    }
}
