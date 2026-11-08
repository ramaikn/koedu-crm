<?php

namespace App\Actions\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\ExtractScheduleDraftJob;
use App\Models\Client;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ai\AiAvailability;
use App\Services\WhatsApp\IncomingMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menyimpan pesan WA masuk ke inbox. Aman dipanggil berulang untuk pesan yang sama:
 * webhook sering terkirim dua kali, dan pesan dengan ID yang sama hanya disimpan sekali.
 */
final class ReceiveWhatsAppMessage
{
    /** @return WaMessage|null null bila pesan ini sudah pernah diterima */
    public function handle(IncomingMessage $incoming): ?WaMessage
    {
        if (WaMessage::where('provider_message_id', $incoming->providerMessageId)->exists()) {
            return null;
        }

        try {
            $message = DB::transaction(fn () => $this->store($incoming));
        } catch (UniqueConstraintViolationException) {
            // Dua webhook dengan pesan yang sama masuk bersamaan; yang kalah balapan diabaikan.
            return null;
        }

        Log::info('Pesan WA masuk disimpan', [
            'conversation_id' => $message->conversation_id,
            'provider' => $incoming->provider,
            'type' => $incoming->type,
        ]);

        // Chat teks dibaca AI di antrean terpisah, supaya webhook tetap cepat dan kegagalan AI tidak memengaruhinya.
        if ($message->type === 'text' && AiAvailability::enabled()) {
            ExtractScheduleDraftJob::dispatch($message->id)->onQueue('ai');
        }

        return $message;
    }

    private function store(IncomingMessage $incoming): WaMessage
    {
        $client = Client::where('phone', $incoming->phone)->first();

        $conversation = WaConversation::firstOrCreate(
            ['phone' => $incoming->phone],
            ['display_name' => $incoming->displayName, 'client_id' => $client?->id],
        );

        // Nama dan client diperbarui bila sebelumnya belum ada (mis. client baru didaftarkan belakangan).
        $conversation->fill([
            'display_name' => $conversation->display_name ?? $incoming->displayName,
            'client_id' => $conversation->client_id ?? $client?->id,
            'last_message_at' => $incoming->sentAt,
        ]);
        $conversation->unread_count++;
        $conversation->save();

        return $conversation->messages()->create([
            'direction' => MessageDirection::Inbound,
            'type' => $incoming->type,
            'body' => $incoming->body,
            'status' => MessageStatus::Received,
            'provider' => $incoming->provider,
            'provider_message_id' => $incoming->providerMessageId,
            'sent_at' => $incoming->sentAt,
        ]);
    }
}
