<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResult;
use Closure;

/** Provider AI palsu untuk test. Mencatat pemanggilan dan mengembalikan jawaban yang disiapkan test. */
final class FakeAiProvider implements AiProvider
{
    /** @var list<array{system: string, prompt: string, schema: array}> */
    public array $calls = [];

    /** @param Closure(): array|array $answer jawaban tetap, atau closure yang boleh melempar exception */
    public function __construct(private Closure|array $answer = [], private string $providerName = 'fake') {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function generateJson(string $system, string $prompt, array $schema): AiResult
    {
        $this->calls[] = compact('system', 'prompt', 'schema');
        $data = $this->answer instanceof Closure ? ($this->answer)() : $this->answer;

        return new AiResult($data, $this->providerName, 'fake-model');
    }

    /** Jawaban umum: client meminta jadwal. */
    public static function scheduleRequest(array $overrides = []): array
    {
        return array_merge([
            'is_schedule_request' => true,
            'confidence' => 0.92,
            'summary' => 'Client minta sesi olah data hari Sabtu jam 10.',
            'client_name' => 'Budi',
            'degree_level' => 'S1',
            'field_of_study' => 'Manajemen',
            'service_note' => 'Olah data SmartPLS',
            'requested_date' => now('Asia/Makassar')->addDays(3)->toDateString(),
            'requested_time' => '10:00',
            'duration_minutes' => 60,
            'session_type' => 'private',
        ], $overrides);
    }
}
