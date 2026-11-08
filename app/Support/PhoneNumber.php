<?php

namespace App\Support;

/**
 * Menyeragamkan nomor WA Indonesia ke format internasional tanpa "+" (62xxxxxxxxxx).
 * WAHA dan WhatsApp Cloud API sama-sama mengirim nomor dalam format ini,
 * jadi chat masuk bisa langsung dicocokkan ke data client.
 */
final class PhoneNumber
{
    public static function normalize(string $raw): string
    {
        // Buang akhiran ID WhatsApp (mis. "62812...@c.us") dan semua karakter non-angka.
        $digits = preg_replace('/\D+/', '', explode('@', $raw)[0]) ?? '';

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }
}
