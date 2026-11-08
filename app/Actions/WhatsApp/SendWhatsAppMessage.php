<?php

namespace App\Actions\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Client;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppProvider;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Satu pintu untuk semua pesan WA keluar (balasan admin, notifikasi tutor, reminder client).
 * Pesan dicatat dulu dengan status "antre", lalu dikirim oleh worker di antrean "whatsapp".
 */
final class SendWhatsAppMessage
{
    public function __construct(private readonly WhatsAppProvider $provider) {}

    public function handle(string $phone, string $body, ?User $sentBy = null, ?string $displayName = null): WaMessage
    {
        $phone = PhoneNumber::normalize($phone);

        $message = DB::transaction(function () use ($phone, $body, $sentBy, $displayName) {
            $conversation = WaConversation::firstOrCreate(
                ['phone' => $phone],
                ['display_name' => $displayName, 'client_id' => Client::where('phone', $phone)->value('id')],
            );

            $conversation->update(['last_message_at' => now()]);

            return $conversation->messages()->create([
                'direction' => MessageDirection::Outbound,
                'type' => 'text',
                'body' => $body,
                'status' => MessageStatus::Queued,
                'provider' => $this->provider->name(),
                'sent_by' => $sentBy?->id,
            ]);
        });

        // Dikirim setelah transaksi paling luar selesai, supaya worker tidak mencari pesan yang belum tersimpan.
        // Penting saat dipanggil dari dalam transaksi lain (mis. pengirim notifikasi yang mengklaim lalu mengantre).
        SendWhatsAppMessageJob::dispatch($message->id)->onQueue('whatsapp')->afterCommit();

        return $message;
    }

    public function reply(WaConversation $conversation, string $body, User $sentBy): WaMessage
    {
        return $this->handle($conversation->phone, $body, $sentBy, $conversation->display_name);
    }
}
