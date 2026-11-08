<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\CloudApiDriver;
use App\Services\WhatsApp\WhatsAppSendException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudApiDriverTest extends TestCase
{
    private function driver(): CloudApiDriver
    {
        return new CloudApiDriver('PHONE_ID', 'token-uji', 'rahasia-app', 'verify-uji', 'v23.0');
    }

    private function metaPayload(): array
    {
        return ['entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => '6281234567890', 'profile' => ['name' => 'Siti']]],
            'messages' => [
                ['id' => 'wamid.1', 'from' => '6281234567890', 'timestamp' => '1762600000', 'type' => 'text', 'text' => ['body' => 'Halo']],
                ['id' => 'wamid.2', 'from' => '6281234567890', 'timestamp' => '1762600001', 'type' => 'image'],
            ],
        ]]]]]];
    }

    public function test_parses_text_and_media_messages(): void
    {
        [$text, $image] = $this->driver()->parseWebhook($this->metaPayload());

        $this->assertSame('wamid.1', $text->providerMessageId);
        $this->assertSame('6281234567890', $text->phone);
        $this->assertSame('Siti', $text->displayName);
        $this->assertSame('Halo', $text->body);
        $this->assertSame('cloud_api', $text->provider);
        $this->assertSame('media', $image->type);
        $this->assertSame('[Pesan image]', $image->body);
    }

    public function test_status_only_webhook_yields_no_messages(): void
    {
        $payload = ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.1', 'status' => 'read']]]]]]]];

        $this->assertSame([], $this->driver()->parseWebhook($payload));
    }

    public function test_signature_validation(): void
    {
        $body = '{"a":1}';
        $valid = 'sha256='.hash_hmac('sha256', $body, 'rahasia-app');

        $this->assertTrue($this->driver()->isValidSignature($body, $valid));
        $this->assertFalse($this->driver()->isValidSignature($body.' ', $valid));
        $this->assertFalse($this->driver()->isValidSignature($body, null));
        $this->assertFalse((new CloudApiDriver('p', 't', null, 'v', 'v23.0'))->isValidSignature($body, $valid), 'tanpa app secret selalu ditolak');
    }

    public function test_verify_challenge(): void
    {
        $this->assertSame('12345', $this->driver()->verifyChallenge('subscribe', 'verify-uji', '12345'));
        $this->assertNull($this->driver()->verifyChallenge('subscribe', 'salah', '12345'));
        $this->assertNull($this->driver()->verifyChallenge('unsubscribe', 'verify-uji', '12345'));
    }

    public function test_send_text_posts_to_graph_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]])]);

        $this->assertSame('wamid.OUT', $this->driver()->sendText('6281234567890', 'Halo'));

        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v23.0/PHONE_ID/messages'
            && $r->hasHeader('Authorization', 'Bearer token-uji')
            && $r['to'] === '6281234567890'
            && $r['text']['body'] === 'Halo');
    }

    public function test_send_text_surfaces_meta_error_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Re-engagement message']], 400)]);

        $this->expectException(WhatsAppSendException::class);
        $this->expectExceptionMessage('Re-engagement message');
        $this->driver()->sendText('6281234567890', 'x');
    }

    public function test_send_text_requires_configuration(): void
    {
        $this->expectException(WhatsAppSendException::class);
        (new CloudApiDriver(null, null, null, null, 'v23.0'))->sendText('6281234567890', 'x');
    }
}
