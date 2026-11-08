<?php

namespace App\Services\Sheets;

use App\Services\Sheets\Tabs\ClientTab;
use App\Services\Sheets\Tabs\JadwalTab;
use App\Services\Sheets\Tabs\PaketClientTab;
use InvalidArgumentException;

/** Daftar tab yang disalin ke Google Sheets. Menambah tab baru cukup dengan satu kelas dan satu baris di sini. */
final class SheetTabs
{
    /** @var array<string, class-string<SheetTab>> */
    private const TABS = [
        'jadwal' => JadwalTab::class,
        'client' => ClientTab::class,
        'paket' => PaketClientTab::class,
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TABS);
    }

    public static function make(string $key): SheetTab
    {
        $class = self::TABS[$key] ?? throw new InvalidArgumentException("Tab Google Sheets tidak dikenal: {$key}");

        return app($class);
    }
}
