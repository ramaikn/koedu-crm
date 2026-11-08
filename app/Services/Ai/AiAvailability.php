<?php

namespace App\Services\Ai;

/** Apakah fitur AI boleh dijalankan: saklar menyala dan ada API key untuk minimal satu provider terpilih. */
final class AiAvailability
{
    public static function enabled(): bool
    {
        if (! config('koedu.ai.enabled')) {
            return false;
        }

        foreach (config('koedu.ai.providers', []) as $provider) {
            $hasCredentials = match ($provider) {
                'gemini' => config('koedu.ai.gemini.keys', []) !== [],
                'openai' => filled(config('koedu.ai.openai.key')),
                default => false,
            };

            if ($hasCredentials) {
                return true;
            }
        }

        return false;
    }
}
