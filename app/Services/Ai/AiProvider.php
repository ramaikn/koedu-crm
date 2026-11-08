<?php

namespace App\Services\Ai;

/**
 * Antarmuka penyedia AI. Aplikasi hanya meminta "jawaban JSON sesuai skema", jadi mengganti
 * Gemini ke provider lain tidak mengubah kode yang memakainya.
 */
interface AiProvider
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $schema  skema JSON gaya OpenAPI (type, properties, nullable, enum, required)
     *
     * @throws AiUnavailableException bila provider tidak bisa dipakai saat ini (limit, jaringan, key salah)
     * @throws AiInvalidResponseException bila permintaan ditolak atau jawabannya bukan JSON yang sah
     */
    public function generateJson(string $system, string $prompt, array $schema): AiResult;
}
