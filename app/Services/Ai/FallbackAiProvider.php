<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/** Mencoba provider sesuai urutan di AI_PROVIDERS. Berhenti di provider pertama yang berhasil. */
final class FallbackAiProvider implements AiProvider
{
    /** @param list<AiProvider> $providers */
    public function __construct(private readonly array $providers) {}

    public function name(): string
    {
        return 'fallback';
    }

    public function generateJson(string $system, string $prompt, array $schema): AiResult
    {
        $failures = [];

        foreach ($this->providers as $provider) {
            try {
                return $provider->generateJson($system, $prompt, $schema);
            } catch (AiException $e) {
                // Jawaban tidak sah dari satu provider belum tentu terjadi di provider lain, jadi tetap lanjut.
                Log::warning('Provider AI gagal, mencoba berikutnya', ['provider' => $provider->name(), 'alasan' => $e->getMessage()]);
                $failures[] = "{$provider->name()}: {$e->getMessage()}";
            }
        }

        throw new AiUnavailableException($failures === [] ? 'Tidak ada provider AI yang dikonfigurasi.' : 'Semua provider AI gagal. '.implode(' | ', $failures));
    }
}
