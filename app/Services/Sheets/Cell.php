<?php

namespace App\Services\Sheets;

/**
 * Menyiapkan isi sel sebelum ditulis ke Google Sheets dengan USER_ENTERED (supaya tanggal, jam, dan angka dikenali).
 * Mode itu juga membaca rumus, jadi teks dari pengguna yang diawali = + - @ diberi apostrof agar tetap menjadi teks.
 * Tanpa ini, nama client seperti "=HYPERLINK(...)" akan dijalankan Sheets sebagai rumus.
 */
final class Cell
{
    /** Teks dari pengguna atau data bebas: aman dari rumus. */
    public static function safe(mixed $value): string|int|float|bool
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'{$text}" : $text;
    }

    /** Teks yang tidak boleh dibaca sebagai angka, mis. nomor telepon (kalau tidak, 6281234567890 tampil 6,28E+12). */
    public static function text(?string $value): string
    {
        return $value === null || $value === '' ? '' : "'{$value}";
    }

    /** @param list<mixed> $row @return list<string|int|float|bool> */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), $row);
    }
}
