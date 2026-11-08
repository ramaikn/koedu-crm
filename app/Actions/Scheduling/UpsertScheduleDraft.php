<?php

namespace App\Actions\Scheduling;

use App\Enums\DraftStatus;
use App\Models\ScheduleDraft;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Scheduling\ScheduleExtraction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menyimpan hasil pembacaan AI sebagai draf jadwal.
 * Satu percakapan hanya punya satu draf yang menunggu: chat lanjutan ("jam 10 saja") melengkapi
 * draf yang ada, bukan membuat draf baru.
 */
final class UpsertScheduleDraft
{
    public function handle(WaConversation $conversation, WaMessage $message, ScheduleExtraction $extraction): ScheduleDraft
    {
        return DB::transaction(function () use ($conversation, $message, $extraction) {
            $draft = $conversation->scheduleDrafts()->pending()->lockForUpdate()->latest('id')->first();

            if ($draft === null) {
                $draft = ScheduleDraft::create([
                    'conversation_id' => $conversation->id,
                    'source_message_id' => $message->id,
                    'client_id' => $conversation->client_id,
                    'status' => DraftStatus::Pending,
                    'extracted' => $this->payload($extraction, $extraction->fields(), [$message->id]),
                    'ai_provider' => $extraction->provider,
                    'ai_model' => $extraction->model,
                ]);
                Log::info('Draf jadwal dibuat dari chat', ['draft_id' => $draft->id, 'conversation_id' => $conversation->id, 'confidence' => $extraction->confidence]);

                return $draft;
            }

            // Rincian baru menimpa yang lama; yang tidak disebut lagi tetap dipertahankan.
            $merged = array_merge($draft->extracted, array_filter($extraction->fields(), fn ($value) => $value !== null));
            $sources = array_values(array_unique([...($draft->extracted['source_message_ids'] ?? []), $message->id]));

            $draft->update([
                'client_id' => $draft->client_id ?? $conversation->client_id,
                'extracted' => $this->payload($extraction, $merged, $sources),
                'ai_provider' => $extraction->provider,
                'ai_model' => $extraction->model,
            ]);
            Log::info('Draf jadwal diperbarui dari chat lanjutan', ['draft_id' => $draft->id, 'conversation_id' => $conversation->id]);

            return $draft;
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<int>  $sourceMessageIds
     * @return array<string, mixed>
     */
    private function payload(ScheduleExtraction $extraction, array $fields, array $sourceMessageIds): array
    {
        return [
            ...$fields,
            'summary' => $extraction->summary,
            'confidence' => round($extraction->confidence, 2),
            'source_message_ids' => $sourceMessageIds,
        ];
    }
}
