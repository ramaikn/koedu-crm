<?php

namespace App\Services\Sheets;

/** Sinkronisasi hanya berjalan bila saklar menyala, ID spreadsheet terisi, dan file kunci ada. */
final class SheetsAvailability
{
    public static function enabled(): bool
    {
        if (! config('koedu.sheets.enabled') || blank(config('koedu.sheets.spreadsheet_id'))) {
            return false;
        }

        $path = config('koedu.sheets.credentials_path');

        return is_string($path) && $path !== '' && is_file($path);
    }
}
