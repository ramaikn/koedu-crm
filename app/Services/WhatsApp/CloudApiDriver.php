<?php

namespace App\Services\WhatsApp;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Driver WhatsApp Cloud API resmi (Meta). Dipakai saat perusahaan beralih dari simulasi ke WA resmi.
 * Catatan bisnis: pesan ke client di luar jendela 24 jam sejak chat terakhir client hanya boleh
 * berupa template yang sudah disetujui Meta. Pengiriman template belum dibuat di driver ini.
 */
final class CloudApiDriver implements WhatsAppProvider
{
    public function __construct(
        private readonly ?string $phoneNumberId,
        private readonly ?string $accessToken,
        private readonly ?string $appSecret,
        private readonly ?string $verifyToken,
        private readonly string $apiVersion,
    ) {}

    public function name(): string
    {
        return 'cloud_api';
    }

    public function sendText(string $phone, string $text): ?string
    {
        if (! $this->phoneNumberId || ! $this->accessToken) {
            throw new WhatsAppSendException('WA Cloud API belum dikonfigurasi (WA_CLOUD_PHONE_NUMBER_ID / WA_CLOUD_ACCESS_TOKEN).');
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => ['body' => $text],
                ]);
        } catch (ConnectionException $e) {
            throw new WhatsAppSendException('Cloud API tidak terjangkau: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? str($response->body())->limit(300);
            throw new WhatsAppSendException("Cloud API menolak pengiriman (HTTP {$response->status()}): {$detail}");
        }

        return $response->json('messages.0.id');
    }

    public function parseWebhook(array $payload): array
    {
        $result = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $names = collect($value['contacts'] ?? [])->mapWithKeys(
                    fn (array $c) => [($c['wa_id'] ?? '') => $c['profile']['name'] ?? null],
                );

                foreach ($value['messages'] ?? [] as $message) {
                    $type = $message['type'] ?? 'unknown';
                    $from = (string) ($message['from'] ?? '');
                    if ($from === '' || empty($message['id'])) {
                        continue;
                    }

                    $result[] = new IncomingMessage(
                        providerMessageId: (string) $message['id'],
                        phone: PhoneNumber::normalize($from),
                        displayName: $names[$from] ?? null,
                        type: $type === 'text' ? 'text' : 'media',
                        body: $type === 'text' ? ($message['text']['body'] ?? null) : "[Pesan {$type}]",
                        sentAt: CarbonImmutable::createFromTimestamp((int) ($message['timestamp'] ?? time())),
                        provider: $this->name(),
                    );
                }
            }
        }

        return $result;
    }

    /** Cek tanda tangan X-Hub-Signature-256 dari Meta pada body mentah. */
    public function isValidSignature(string $rawBody, ?string $header): bool
    {
        if (! $this->appSecret || ! $header) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $this->appSecret), $header);
    }

    /** Jawaban verifikasi webhook (GET hub.challenge). Null bila token tidak cocok. */
    public function verifyChallenge(?string $mode, ?string $token, ?string $challenge): ?string
    {
        if ($mode === 'subscribe' && $this->verifyToken && $token !== null && hash_equals($this->verifyToken, $token)) {
            return $challenge;
        }

        return null;
    }
}
