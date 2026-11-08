<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Provider cadangan untuk layanan berformat OpenAI Chat Completions (OpenAI, OpenRouter, Groq, model lokal).
 * Skema JSON disertakan di instruksi sistem, karena dukungan skema ketat berbeda-beda antar layanan.
 */
final class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function generateJson(string $system, string $prompt, array $schema): AiResult
    {
        if (! $this->apiKey) {
            throw new AiUnavailableException('AI_OPENAI_API_KEY belum diisi.');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model' => $this->model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system."\n\nBalas HANYA dengan satu objek JSON yang sesuai skema ini:\n".json_encode($schema, JSON_UNESCAPED_UNICODE)],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AiUnavailableException('Koneksi ke provider OpenAI-compatible gagal.', previous: $e);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500 || in_array($status, [401, 403], true)) {
            throw new AiUnavailableException("Provider OpenAI-compatible tidak bisa dipakai (HTTP {$status}).");
        }
        if ($response->failed()) {
            throw new AiInvalidResponseException("Provider OpenAI-compatible menolak permintaan (HTTP {$status}).");
        }

        $content = $response->json('choices.0.message.content');
        $data = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($data)) {
            throw new AiInvalidResponseException('Jawaban provider bukan JSON yang sah.');
        }

        return new AiResult($data, $this->name(), $this->model);
    }
}
