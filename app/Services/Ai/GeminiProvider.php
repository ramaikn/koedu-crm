<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google AI Studio (Gemini API) dengan banyak API key dan daftar model cadangan.
 *
 * Key yang kena limit (HTTP 429) diistirahatkan sementara, lalu permintaan diulang dengan key berikutnya.
 * Key yang ditolak (tidak valid) diistirahatkan lebih lama. Model yang sedang sibuk (503), habis masa
 * pakainya (404), atau timeout diganti dengan model berikutnya di daftar, dengan key yang sama.
 * Status istirahat key disimpan di cache supaya dipakai bersama semua worker.
 * API key dikirim lewat header, tidak pernah lewat URL atau log.
 */
final class GeminiProvider implements AiProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta';

    /** Key yang ditolak (salah, dicabut, atau tanpa izin) diistirahatkan selama ini sebelum dicoba lagi. */
    private const REJECTED_KEY_COOLDOWN_SECONDS = 3600;

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $models  urutan prioritas; model berikutnya dipakai bila yang sebelumnya gagal
     */
    public function __construct(
        private readonly array $keys,
        private readonly array $models,
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
        if ($this->models === []) {
            throw new AiUnavailableException('GEMINI_MODEL belum diisi.');
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
            foreach ($this->models as $model) {
                try {
                    $response = Http::withHeaders(['x-goog-api-key' => $key])
                        ->acceptJson()
                        ->timeout($this->timeoutSeconds)
                        ->post(self::ENDPOINT."/models/{$model}:generateContent", $body);
                } catch (ConnectionException) {
                    $lastReason = "koneksi ke {$model} gagal";
                    Log::warning('Gemini: koneksi gagal, mencoba model berikutnya', ['key' => $index + 1, 'model' => $model]);

                    continue;
                }

                if ($response->successful()) {
                    return new AiResult($this->parse($response), $this->name(), $model);
                }

                $outcome = $this->handleFailure($response, $index, $model);
                $lastReason = $outcome['reason'];

                if ($outcome['next'] === 'key') {
                    break; // key ini tidak bisa dipakai sekarang; coba key berikutnya
                }
            }
        }

        throw new AiUnavailableException("Semua API key dan model Gemini gagal ({$lastReason}).");
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

    /**
     * Cek satu key dengan permintaan generate sungguhan yang sangat kecil. Daftar model saja tidak cukup:
     * model yang sudah dipensiunkan masih tampil di daftar tetapi menolak generate (404).
     *
     * @return array{status: int, message: ?string} status 0 bila koneksi gagal; message dari Google bila gagal
     */
    public function probe(int $index, string $model): array
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->keys[$index]])
                ->timeout($this->timeoutSeconds)
                ->post(self::ENDPOINT."/models/{$model}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => [['text' => 'Balas dengan kata: ok']]]],
                    'generationConfig' => ['maxOutputTokens' => 16],
                ]);
        } catch (ConnectionException) {
            return ['status' => 0, 'message' => null];
        }

        return [
            'status' => $response->status(),
            'message' => $response->successful() ? null : str($response->json('error.message') ?? '')->limit(200)->toString(),
        ];
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

    /**
     * Menentukan nasib key atau model setelah gagal.
     *
     * @return array{reason: string, next: 'model'|'key'} next = lanjut ke model berikutnya, atau ke key berikutnya
     */
    private function handleFailure(Response $response, int $index, string $model): array
    {
        $status = $response->status();
        $key = $this->keys[$index];

        if ($status === 429) {
            $this->cache->put($this->cooldownKey($key), true, $this->cooldownSeconds);
            Log::warning('Gemini: key kena limit, diistirahatkan', ['key' => $index + 1, 'detik' => $this->cooldownSeconds]);

            return ['reason' => 'kuota habis (429)', 'next' => 'key'];
        }

        // 401/403 = key tidak punya izin. 400 dengan API_KEY_INVALID = key salah. Keduanya urusan key, bukan isi permintaan.
        if (in_array($status, [401, 403], true) || ($status === 400 && str_contains($response->body(), 'API_KEY_INVALID'))) {
            $this->cache->put($this->cooldownKey($key), true, self::REJECTED_KEY_COOLDOWN_SECONDS);
            Log::error('Gemini: key ditolak, diistirahatkan', ['key' => $index + 1, 'status' => $status]);

            return ['reason' => "key ditolak ({$status})", 'next' => 'key'];
        }

        // 404 = model sudah dipensiunkan atau tidak tersedia untuk akun ini. 5xx = Google sedang sibuk.
        // Keduanya urusan model, jadi model berikutnya yang dicoba (dengan key yang sama).
        if ($status === 404 || $status >= 500) {
            Log::warning('Gemini: model tidak bisa dipakai, mencoba model berikutnya', ['model' => $model, 'status' => $status]);

            return ['reason' => "{$model} tidak bisa dipakai ({$status})", 'next' => 'model'];
        }

        // Selain itu (mis. skema salah): model atau key lain tidak akan membantu.
        Log::error('Gemini menolak permintaan', ['model' => $model, 'status' => $status, 'body' => str($response->body())->limit(300)->toString()]);

        throw new AiInvalidResponseException("Gemini menolak permintaan (HTTP {$status}). Periksa skema permintaan.");
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
