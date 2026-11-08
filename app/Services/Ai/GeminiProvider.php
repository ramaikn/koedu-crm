<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google AI Studio (Gemini API) dengan banyak API key.
 *
 * Key yang kena limit (HTTP 429) diistirahatkan sementara, lalu permintaan diulang dengan key berikutnya.
 * Key yang ditolak (tidak valid) diistirahatkan lebih lama. Status istirahat disimpan di cache supaya
 * dipakai bersama oleh semua worker. API key dikirim lewat header, tidak pernah lewat URL atau log.
 */
final class GeminiProvider implements AiProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta';

    /** Key yang ditolak (salah, dicabut, atau tanpa izin) diistirahatkan selama ini sebelum dicoba lagi. */
    private const REJECTED_KEY_COOLDOWN_SECONDS = 3600;

    /** @param list<string> $keys */
    public function __construct(
        private readonly array $keys,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly int $cooldownSeconds,
        private readonly Cache $cache,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function generateJson(string $system, string $prompt, array $schema): AiResult
    {
        $available = $this->availableKeys();
        if ($available === []) {
            throw new AiUnavailableException('Tidak ada API key Gemini yang bisa dipakai (belum diisi atau semuanya sedang istirahat).');
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                // Ekstraksi data harus konsisten, bukan kreatif.
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->toGeminiSchema($schema),
            ],
        ];

        $lastReason = 'tidak diketahui';

        foreach ($available as $index => $key) {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => $key])
                    ->acceptJson()
                    ->timeout($this->timeoutSeconds)
                    ->post(self::ENDPOINT."/models/{$this->model}:generateContent", $body);
            } catch (ConnectionException $e) {
                $lastReason = 'koneksi ke Gemini gagal';
                Log::warning('Gemini: koneksi gagal, mencoba key berikutnya', ['key' => $index + 1]);

                continue;
            }

            if ($response->successful()) {
                return new AiResult($this->parse($response), $this->name(), $this->model);
            }

            $lastReason = $this->handleFailure($response, $index);
        }

        throw new AiUnavailableException("Semua API key Gemini gagal ({$lastReason}).");
    }

    /**
     * Nama key tanpa membuka isinya, untuk perintah ai:check.
     *
     * @return list<string>
     */
    public function keyIds(): array
    {
        return array_map(fn (string $key) => $this->keyId($key), $this->keys);
    }

    /** Cek satu key terhadap model yang dikonfigurasi. Mengembalikan kode HTTP, atau 0 bila koneksi gagal. */
    public function probe(int $index): int
    {
        try {
            return Http::withHeaders(['x-goog-api-key' => $this->keys[$index]])
                ->timeout($this->timeoutSeconds)
                ->get(self::ENDPOINT."/models/{$this->model}")
                ->status();
        } catch (ConnectionException) {
            return 0;
        }
    }

    public function isCoolingDown(int $index): bool
    {
        return isset($this->keys[$index]) && $this->cache->has($this->cooldownKey($this->keys[$index]));
    }

    /** @return array<int, string> index asli => key, hanya yang tidak sedang istirahat */
    private function availableKeys(): array
    {
        return array_filter($this->keys, fn (string $key) => ! $this->cache->has($this->cooldownKey($key)), ARRAY_FILTER_USE_BOTH);
    }

    /** Menentukan nasib key setelah gagal dan mengembalikan alasan singkat untuk pesan error. */
    private function handleFailure(Response $response, int $index): string
    {
        $status = $response->status();
        $key = $this->keys[$index];

        if ($status === 429) {
            $this->cache->put($this->cooldownKey($key), true, $this->cooldownSeconds);
            Log::warning('Gemini: key kena limit, diistirahatkan', ['key' => $index + 1, 'detik' => $this->cooldownSeconds]);

            return 'kuota habis (429)';
        }

        // 401/403 = key tidak punya izin. 400 dengan API_KEY_INVALID = key salah. Keduanya urusan key, bukan isi permintaan.
        if (in_array($status, [401, 403], true) || ($status === 400 && str_contains($response->body(), 'API_KEY_INVALID'))) {
            $this->cache->put($this->cooldownKey($key), true, self::REJECTED_KEY_COOLDOWN_SECONDS);
            Log::error('Gemini: key ditolak, diistirahatkan', ['key' => $index + 1, 'status' => $status]);

            return "key ditolak ({$status})";
        }

        if ($status >= 500) {
            Log::warning('Gemini: server bermasalah, mencoba key berikutnya', ['status' => $status]);

            return "server Gemini bermasalah ({$status})";
        }

        // Selain itu (mis. skema atau model salah): key lain tidak akan membantu.
        Log::error('Gemini menolak permintaan', ['status' => $status, 'body' => str($response->body())->limit(300)->toString()]);

        throw new AiInvalidResponseException("Gemini menolak permintaan (HTTP {$status}). Periksa GEMINI_MODEL dan skema.");
    }

    /** @return array<string, mixed> */
    private function parse(Response $response): array
    {
        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            $reason = $response->json('promptFeedback.blockReason') ?? $response->json('candidates.0.finishReason') ?? 'kosong';
            throw new AiInvalidResponseException("Gemini tidak memberi jawaban ({$reason}).");
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new AiInvalidResponseException('Jawaban Gemini bukan JSON yang sah.');
        }

        return $data;
    }

    /** Skema gaya OpenAPI ke format Gemini: nama tipe huruf besar, properti yang tidak didukung dibuang. */
    private function toGeminiSchema(array $schema): array
    {
        $out = [];
        foreach (['description', 'nullable', 'enum', 'required'] as $key) {
            if (array_key_exists($key, $schema)) {
                $out[$key] = $schema[$key];
            }
        }
        if (isset($schema['type'])) {
            $out['type'] = strtoupper($schema['type']);
        }
        if (isset($schema['properties'])) {
            $out['properties'] = array_map(fn ($p) => $this->toGeminiSchema($p), $schema['properties']);
        }
        if (isset($schema['items'])) {
            $out['items'] = $this->toGeminiSchema($schema['items']);
        }

        return $out;
    }

    private function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 12);
    }

    private function cooldownKey(string $key): string
    {
        return 'ai:gemini:cooldown:'.$this->keyId($key);
    }
}
