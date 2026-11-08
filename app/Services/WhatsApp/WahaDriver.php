<?php

namespace App\Services\WhatsApp;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver WAHA (WhatsApp HTTP API self-hosted). Dipakai untuk simulasi.
 * Webhook diterima dengan event "message"; pesan keluar lewat POST /api/sendText.
 */
final class WahaDriver implements WhatsAppProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $session,
        private readonly ?string $apiKey,
    ) {}

    public function name(): string
    {
        return 'waha';
    }

    public function sendText(string $phone, string $text): ?string
    {
        try {
            $response = $this->client()->post('/api/sendText', [
                'session' => $this->session,
                'chatId' => $phone.'@c.us',
                'text' => $text,
            ]);
        } catch (ConnectionException $e) {
            throw new WhatsAppSendException('WAHA tidak terjangkau: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new WhatsAppSendException("WAHA menolak pengiriman (HTTP {$response->status()}): ".str($response->body())->limit(300));
        }

        $id = $response->json('id');

        // Bentuk ID berbeda antar engine: string biasa, atau objek dengan _serialized.
        return is_array($id) ? ($id['_serialized'] ?? null) : $id;
    }

    public function parseWebhook(array $payload): array
    {
        if (($payload['event'] ?? null) !== 'message') {
            return [];
        }

        $message = $payload['payload'] ?? null;
        if (! is_array($message) || ($message['fromMe'] ?? false) || empty($message['id'])) {
            return [];
        }

        $from = (string) ($message['from'] ?? '');
        $phone = $this->resolvePhone($from, $message);
        if ($phone === null) {
            return [];
        }

        $hasMedia = (bool) ($message['hasMedia'] ?? false);
        $body = $message['body'] ?? null;

        return [new IncomingMessage(
            providerMessageId: (string) $message['id'],
            phone: $phone,
            displayName: $message['_data']['Info']['PushName'] ?? $message['_data']['notifyName'] ?? null,
            type: $hasMedia ? 'media' : 'text',
            body: ($body === null || $body === '') && $hasMedia ? '[Pesan media]' : $body,
            sentAt: CarbonImmutable::createFromTimestamp((int) ($message['timestamp'] ?? time())),
            provider: $this->name(),
        )];
    }

    /**
     * Ambil nomor telepon dari ID pengirim. Grup, status, dan channel diabaikan.
     * ID berakhiran @lid (ID privasi WhatsApp) dicari nomornya lewat API WAHA.
     */
    private function resolvePhone(string $from, array $message): ?string
    {
        if (str_ends_with($from, '@g.us') || str_ends_with($from, '@newsletter') || str_starts_with($from, 'status@')) {
            return null;
        }

        if (str_ends_with($from, '@c.us') || str_ends_with($from, '@s.whatsapp.net')) {
            return PhoneNumber::normalize($from);
        }

        if (str_ends_with($from, '@lid')) {
            // Beberapa engine menyertakan nomor asli di data mentah, tanpa perlu panggilan API.
            $alt = $message['_data']['Info']['SenderAlt'] ?? null;
            if (is_string($alt) && $alt !== '') {
                return PhoneNumber::normalize($alt);
            }

            return $this->lookupLid($from);
        }

        Log::warning('WAHA: format pengirim tidak dikenal, pesan diabaikan', ['from_suffix' => substr($from, strpos($from, '@') ?: 0)]);

        return null;
    }

    private function lookupLid(string $lid): ?string
    {
        try {
            $response = $this->client()->get('/api/'.rawurlencode($this->session).'/lids/'.rawurlencode($lid));
        } catch (ConnectionException $e) {
            Log::warning('WAHA: gagal mencari nomor dari LID', ['error' => $e->getMessage()]);

            return null;
        }

        $pn = $response->successful() ? $response->json('pn') : null;

        if (! is_string($pn) || $pn === '') {
            Log::warning('WAHA: nomor untuk LID tidak ditemukan, pesan diabaikan', ['status' => $response->status()]);

            return null;
        }

        return PhoneNumber::normalize($pn);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(array_filter(['X-Api-Key' => $this->apiKey]))
            ->acceptJson()
            ->timeout(15);
    }
}
