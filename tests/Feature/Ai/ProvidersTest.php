<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiException;
use App\Services\Ai\AiInvalidResponseException;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\FallbackAiProvider;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\OpenAiCompatibleProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvidersTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'ok' => ['type' => 'boolean'],
            'name' => ['type' => 'string', 'nullable' => true, 'unsupported' => 'dibuang?'],
        ],
        'required' => ['ok'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('array')->flush();
    }

    private function gemini(array $keys = ['key-a', 'key-b']): GeminiProvider
    {
        return new GeminiProvider($keys, 'gemini-test', 5, 60, Cache::store('array'));
    }

    private function geminiAnswer(array $data = ['ok' => true]): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]]];
    }

    private function usedKeys(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->header('x-goog-api-key')[0])->values()->all();
    }

    // --- Gemini ---

    public function test_gemini_sends_key_in_header_and_converts_schema(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiAnswer(['ok' => true, 'name' => 'Budi']))]);

        $result = $this->gemini()->generateJson('Sistem', 'Pesan', self::SCHEMA);

        $this->assertSame(['ok' => true, 'name' => 'Budi'], $result->data);
        $this->assertSame('gemini', $result->provider);
        $this->assertSame('gemini-test', $result->model);

        Http::assertSent(function (Request $request) {
            $config = $request['generationConfig'];

            return str_contains($request->url(), '/models/gemini-test:generateContent')
                && ! str_contains($request->url(), 'key-a')
                && $request->hasHeader('x-goog-api-key', 'key-a')
                && $request['systemInstruction']['parts'][0]['text'] === 'Sistem'
                && $config['temperature'] === 0
                && $config['responseMimeType'] === 'application/json'
                && $config['responseSchema']['type'] === 'OBJECT'
                && $config['responseSchema']['properties']['ok']['type'] === 'BOOLEAN'
                && $config['responseSchema']['properties']['name']['nullable'] === true
                && ! isset($config['responseSchema']['properties']['name']['unsupported'])
                && $config['responseSchema']['required'] === ['ok'];
        });
    }

    public function test_rate_limited_key_rests_and_next_key_is_used(): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'key-a')
            ? Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)
            : Http::response($this->geminiAnswer()));
        $provider = $this->gemini();

        $this->assertSame(['ok' => true], $provider->generateJson('s', 'p', self::SCHEMA)->data);
        $this->assertTrue($provider->isCoolingDown(0));
        $this->assertFalse($provider->isCoolingDown(1));
        $this->assertSame(['key-a', 'key-b'], $this->usedKeys());

        // Panggilan berikutnya langsung memakai key-b, tanpa mencoba key-a yang sedang istirahat.
        Http::fake(['*' => Http::response($this->geminiAnswer())]);
        $provider->generateJson('s', 'p', self::SCHEMA);
        $this->assertSame(['key-b'], $this->usedKeys());
    }

    public function test_all_keys_exhausted_throws_unavailable_then_skips_network(): void
    {
        Http::fake(['*' => Http::response([], 429)]);
        $provider = $this->gemini();

        try {
            $provider->generateJson('s', 'p', self::SCHEMA);
            $this->fail('Seharusnya gagal.');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('kuota habis (429)', $e->getMessage());
        }
        $this->assertSame(['key-a', 'key-b'], $this->usedKeys());

        Http::fake();
        $this->expectException(AiUnavailableException::class);
        try {
            $provider->generateJson('s', 'p', self::SCHEMA);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_rejected_key_is_rested_long_and_next_key_is_used(): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'key-a')
            ? Http::response(['error' => ['details' => [['reason' => 'API_KEY_INVALID']]]], 400)
            : Http::response($this->geminiAnswer()));
        $provider = $this->gemini();

        $this->assertSame(['ok' => true], $provider->generateJson('s', 'p', self::SCHEMA)->data);
        $this->assertTrue($provider->isCoolingDown(0));
    }

    public function test_forbidden_key_is_treated_as_rejected(): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'key-a') ? Http::response([], 403) : Http::response($this->geminiAnswer()));

        $this->assertSame(['ok' => true], $this->gemini()->generateJson('s', 'p', self::SCHEMA)->data);
    }

    public function test_server_error_tries_next_key_without_resting_the_first(): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'key-a') ? Http::response([], 503) : Http::response($this->geminiAnswer()));
        $provider = $this->gemini();

        $this->assertSame(['ok' => true], $provider->generateJson('s', 'p', self::SCHEMA)->data);
        $this->assertFalse($provider->isCoolingDown(0), 'gangguan server bukan salah key');
    }

    public function test_bad_request_is_not_retried_with_other_keys(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid JSON payload']], 400)]);

        try {
            $this->gemini()->generateJson('s', 'p', self::SCHEMA);
            $this->fail('Seharusnya ditolak.');
        } catch (AiInvalidResponseException $e) {
            $this->assertStringContainsString('GEMINI_MODEL', $e->getMessage());
        }

        $this->assertSame(['key-a'], $this->usedKeys());
    }

    public function test_connection_failure_moves_to_next_key(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new ConnectionException('timeout');
            }

            return Http::response($this->geminiAnswer());
        });

        $this->assertSame(['ok' => true], $this->gemini()->generateJson('s', 'p', self::SCHEMA)->data);
    }

    public function test_blocked_or_malformed_answers_are_invalid_responses(): void
    {
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);
        try {
            $this->gemini()->generateJson('s', 'p', self::SCHEMA);
            $this->fail('Seharusnya gagal.');
        } catch (AiInvalidResponseException $e) {
            $this->assertStringContainsString('SAFETY', $e->getMessage());
        }

        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'bukan json']]]]]])]);
        $this->expectException(AiInvalidResponseException::class);
        $this->gemini()->generateJson('s', 'p', self::SCHEMA);
    }

    public function test_no_keys_is_unavailable_and_key_ids_hide_the_key(): void
    {
        $this->expectException(AiUnavailableException::class);
        try {
            $this->gemini([])->generateJson('s', 'p', self::SCHEMA);
        } finally {
            $ids = $this->gemini(['key-a'])->keyIds();
            $this->assertCount(1, $ids);
            $this->assertStringNotContainsString('key-a', $ids[0]);
        }
    }

    // --- OpenAI-compatible ---

    private function openai(?string $key = 'sk-test'): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider('https://llm.example/v1', $key, 'model-x', 5);
    }

    public function test_openai_compatible_success_and_request_shape(): void
    {
        Http::fake(['llm.example/*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]])]);

        $result = $this->openai()->generateJson('Sistem', 'Pesan', self::SCHEMA);

        $this->assertSame(['ok' => true], $result->data);
        $this->assertSame('openai', $result->provider);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://llm.example/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['response_format']['type'] === 'json_object'
            && str_contains($r['messages'][0]['content'], '"required":["ok"]')
            && $r['messages'][1]['content'] === 'Pesan');
    }

    public function test_openai_compatible_error_mapping(): void
    {
        // Satu urutan respons: stub Http::fake yang dipanggil berulang menumpuk, bukan menggantikan.
        Http::fake(['*' => Http::sequence()->push([], 429)->push([], 503)->push([], 401)->push([], 400)]);
        foreach ([429 => AiUnavailableException::class, 503 => AiUnavailableException::class, 401 => AiUnavailableException::class, 400 => AiInvalidResponseException::class] as $status => $exception) {
            try {
                $this->openai()->generateJson('s', 'p', self::SCHEMA);
                $this->fail("HTTP {$status} seharusnya gagal.");
            } catch (AiException $e) {
                $this->assertInstanceOf($exception, $e, "HTTP {$status}");
            }
        }
    }

    public function test_openai_compatible_non_json_content_is_invalid(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'teks biasa']]]])]);

        $this->expectException(AiInvalidResponseException::class);
        $this->openai()->generateJson('s', 'p', self::SCHEMA);
    }

    public function test_openai_compatible_without_key_is_unavailable(): void
    {
        $this->expectException(AiUnavailableException::class);
        $this->openai(null)->generateJson('s', 'p', self::SCHEMA);
    }

    // --- Fallback dan ketersediaan ---

    public function test_fallback_uses_the_next_provider_when_one_fails(): void
    {
        $failing = new FakeAiProvider(fn () => throw new AiUnavailableException('limit'), 'a');
        $invalid = new FakeAiProvider(fn () => throw new AiInvalidResponseException('bukan json'), 'b');
        $working = new FakeAiProvider(['ok' => true], 'c');

        $result = (new FallbackAiProvider([$failing, $invalid, $working]))->generateJson('s', 'p', self::SCHEMA);

        $this->assertSame('c', $result->provider);
        $this->assertCount(1, $failing->calls);
        $this->assertCount(1, $invalid->calls);
    }

    public function test_fallback_reports_every_failure_when_all_providers_fail(): void
    {
        $a = new FakeAiProvider(fn () => throw new AiUnavailableException('limit'), 'a');
        $b = new FakeAiProvider(fn () => throw new AiUnavailableException('timeout'), 'b');

        try {
            (new FallbackAiProvider([$a, $b]))->generateJson('s', 'p', self::SCHEMA);
            $this->fail('Seharusnya gagal.');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('a: limit', $e->getMessage());
            $this->assertStringContainsString('b: timeout', $e->getMessage());
        }

        $this->expectException(AiUnavailableException::class);
        (new FallbackAiProvider([]))->generateJson('s', 'p', self::SCHEMA);
    }

    public function test_container_builds_the_provider_chain_from_config(): void
    {
        config(['koedu.ai.providers' => ['gemini', 'openai'], 'koedu.ai.gemini.keys' => ['k1'], 'koedu.ai.openai.key' => 'sk']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]]),
        ]);

        $result = app(\App\Services\Ai\AiProvider::class)->generateJson('s', 'p', self::SCHEMA);

        $this->assertSame('openai', $result->provider, 'Gemini limit, pindah ke provider cadangan');

        config(['koedu.ai.providers' => ['telepati']]);
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\Ai\AiProvider::class);
    }

    public function test_availability_requires_switch_and_credentials(): void
    {
        config(['koedu.ai.enabled' => true, 'koedu.ai.providers' => ['gemini'], 'koedu.ai.gemini.keys' => []]);
        $this->assertFalse(AiAvailability::enabled(), 'tanpa key');

        config(['koedu.ai.gemini.keys' => ['k']]);
        $this->assertTrue(AiAvailability::enabled());

        config(['koedu.ai.enabled' => false]);
        $this->assertFalse(AiAvailability::enabled(), 'saklar mati');

        config(['koedu.ai.enabled' => true, 'koedu.ai.providers' => ['openai'], 'koedu.ai.gemini.keys' => ['k'], 'koedu.ai.openai.key' => null]);
        $this->assertFalse(AiAvailability::enabled(), 'key Gemini tidak dihitung bila provider terpilih hanya openai');
    }
}
