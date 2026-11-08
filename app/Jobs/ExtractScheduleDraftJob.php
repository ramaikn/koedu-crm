<?php

namespace App\Jobs;

use App\Actions\Scheduling\UpsertScheduleDraft;
use App\Enums\MessageDirection;
use App\Models\WaMessage;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiException;
use App\Services\Scheduling\ScheduleRequestExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Membaca chat masuk dengan AI dan membuat draf jadwal bila itu permintaan jadwal.
 * Kegagalan AI tidak boleh mengganggu chat: pesan sudah tersimpan, admin tetap bisa membalas dan mengatur jadwal manual.
 */
class ExtractScheduleDraftJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30];
    }

    public function __construct(public readonly int $messageId) {}

    public function handle(ScheduleRequestExtractor $extractor, UpsertScheduleDraft $upsert): void
    {
        if (! AiAvailability::enabled()) {
            return;
        }

        $message = WaMessage::with('conversation.client')->find($this->messageId);
        if ($message === null || $message->direction !== MessageDirection::Inbound || mb_strlen(trim((string) $message->body)) < 4) {
            return;
        }

        $conversation = $message->conversation;

        // Pengaman biaya dan pesan beruntun: batas panggilan AI per percakapan per jam.
        $allowed = RateLimiter::attempt(
            "ai-extract:{$conversation->id}",
            (int) config('koedu.ai.extraction.max_calls_per_hour'),
            fn () => true,
            3600,
        );
        if (! $allowed) {
            Log::warning('Batas panggilan AI per percakapan tercapai, chat dilewati', ['conversation_id' => $conversation->id]);

            return;
        }

        $extraction = $extractor->extract($conversation);

        if (! $extraction->isScheduleRequest || $extraction->confidence < (float) config('koedu.ai.extraction.min_confidence')) {
            Log::info('Chat bukan permintaan jadwal, tidak dibuat draf', [
                'message_id' => $message->id,
                'is_request' => $extraction->isScheduleRequest,
                'confidence' => $extraction->confidence,
            ]);

            return;
        }

        $upsert->handle($conversation, $message, $extraction);
    }

    public function failed(Throwable $e): void
    {
        Log::error('AI gagal membuat draf jadwal dari chat', [
            'message_id' => $this->messageId,
            'tipe' => $e instanceof AiException ? 'ai' : $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
