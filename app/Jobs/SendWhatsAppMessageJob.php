<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppProvider;
use App\Services\WhatsApp\WhatsAppSendException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Mengirim satu pesan WA keluar. Dicoba ulang sampai 3 kali, lalu ditandai gagal. */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @return list<int> detik jeda sebelum percobaan ke-2 dan ke-3 */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(public readonly int $messageId) {}

    public function handle(WhatsAppProvider $provider): void
    {
        $message = WaMessage::with('conversation')->find($this->messageId);

        // Sudah terkirim atau dihapus: tidak kirim dua kali kalau job terpanggil ulang.
        if ($message === null || $message->status !== MessageStatus::Queued) {
            return;
        }

        try {
            $providerId = $provider->sendText($message->conversation->phone, (string) $message->body);
        } catch (WhatsAppSendException $e) {
            // Status tetap "antre" supaya percobaan berikutnya jalan. failed() menandai gagal di akhir.
            Log::warning('Kirim WA gagal, akan dicoba ulang', [
                'message_id' => $message->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $message->update([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $providerId,
            'sent_at' => now(),
            'error' => null,
        ]);

        Log::info('Pesan WA terkirim', ['message_id' => $message->id, 'provider' => $provider->name()]);
    }

    public function failed(Throwable $e): void
    {
        WaMessage::whereKey($this->messageId)->update([
            'status' => MessageStatus::Failed,
            'error' => str($e->getMessage())->limit(500)->toString(),
        ]);

        Log::error('Pesan WA gagal terkirim setelah semua percobaan', [
            'message_id' => $this->messageId,
            'error' => $e->getMessage(),
        ]);
    }
}
