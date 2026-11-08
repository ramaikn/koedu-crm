<?php

namespace App\Services\WhatsApp;

/**
 * Antarmuka gateway WhatsApp. Seluruh aplikasi hanya bicara dengan antarmuka ini,
 * jadi pindah dari WAHA (simulasi) ke WhatsApp Cloud API resmi cukup mengganti WHATSAPP_DRIVER.
 */
interface WhatsAppProvider
{
    /** Nama driver, disimpan di wa_messages.provider. */
    public function name(): string;

    /**
     * Kirim pesan teks ke nomor (format 62xxx).
     *
     * @return string|null ID pesan dari provider (bila ada)
     *
     * @throws WhatsAppSendException bila provider menolak atau tidak terjangkau
     */
    public function sendText(string $phone, string $text): ?string;

    /**
     * Ubah payload webhook mentah dari provider menjadi pesan masuk yang seragam.
     * Pesan yang tidak relevan (dari diri sendiri, grup, status, bukan pesan) tidak ikut dikembalikan.
     *
     * @param  array<string, mixed>  $payload
     * @return list<IncomingMessage>
     */
    public function parseWebhook(array $payload): array;
}
