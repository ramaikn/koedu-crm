<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\WahaDriver;
use App\Services\WhatsApp\WhatsAppSendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaDriverTest extends TestCase
{
    private function driver(): WahaDriver
    {
        return new WahaDriver('http://waha:3000', 'default', 'kunci-uji');
    }

    private function webhook(array $overrides = [], array $payloadOverrides = []): array
    {
        return array_replace_recursive([
            'event' => 'message',
            'payload' => array_replace([
                'id' => 'false_6281234567890@c.us_ABC',
                'timestamp' => 1_762_600_000,
                'from' => '6281234567890@c.us',
                'fromMe' => false,
                'body' => 'Halo',
                'hasMedia' => false,
                '_data' => ['Info' => ['PushName' => 'Budi']],
            ], $payloadOverrides),
        ], $overrides);
    }

    public function test_parses_text_message(): void
    {
        [$msg] = $this->driver()->parseWebhook($this->webhook());

        $this->assertSame('false_6281234567890@c.us_ABC', $msg->providerMessageId);
        $this->assertSame('6281234567890', $msg->phone);
        $this->assertSame('Budi', $msg->displayName);
        $this->assertSame('text', $msg->type);
        $this->assertSame('Halo', $msg->body);
        $this->assertSame(1_762_600_000, $msg->sentAt->timestamp);
        $this->assertSame('waha', $msg->provider);
    }

    public function test_ignores_non_message_events_own_messages_groups_and_status(): void
    {
        $driver = $this->driver();

        $this->assertSame([], $driver->parseWebhook(['event' => 'session.status', 'payload' => ['status' => 'WORKING']]));
        $this->assertSame([], $driver->parseWebhook($this->webhook(payloadOverrides: ['fromMe' => true])));
        $this->assertSame([], $driver->parseWebhook($this->webhook(payloadOverrides: ['from' => '120363@g.us'])));
        $this->assertSame([], $driver->parseWebhook($this->webhook(payloadOverrides: ['from' => 'status@broadcast'])));
        $this->assertSame([], $driver->parseWebhook($this->webhook(payloadOverrides: ['id' => ''])));
    }

    public function test_media_message_gets_placeholder_body(): void
    {
        [$msg] = $this->driver()->parseWebhook($this->webhook(payloadOverrides: ['hasMedia' => true, 'body' => '']));

        $this->assertSame('media', $msg->type);
        $this->assertSame('[Pesan media]', $msg->body);
    }

    public function test_resolves_lid_through_waha_api(): void
    {
        Http::fake(['waha:3000/api/default/lids/*' => Http::response(['lid' => '999@lid', 'pn' => '6281234567890@c.us'])]);

        [$msg] = $this->driver()->parseWebhook($this->webhook(payloadOverrides: ['from' => '999@lid']));

        $this->assertSame('6281234567890', $msg->phone);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Api-Key', 'kunci-uji') && str_contains($r->url(), '/lids/999%40lid'));
    }

    public function test_uses_sender_alt_for_lid_without_api_call(): void
    {
        Http::fake();

        [$msg] = $this->driver()->parseWebhook($this->webhook(payloadOverrides: [
            'from' => '999@lid',
            '_data' => ['Info' => ['SenderAlt' => '6281234567890@s.whatsapp.net']],
        ]));

        $this->assertSame('6281234567890', $msg->phone);
        Http::assertNothingSent();
    }

    public function test_drops_lid_message_when_phone_cannot_be_found(): void
    {
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $this->assertSame([], $this->driver()->parseWebhook($this->webhook(payloadOverrides: ['from' => '999@lid'])));
    }

    public function test_send_text_posts_to_waha_and_returns_id(): void
    {
        Http::fake(['waha:3000/api/sendText' => Http::response(['id' => 'true_6281234567890@c.us_XYZ'])]);

        $id = $this->driver()->sendText('6281234567890', 'Halo juga');

        $this->assertSame('true_6281234567890@c.us_XYZ', $id);
        Http::assertSent(fn ($r) => $r['session'] === 'default'
            && $r['chatId'] === '6281234567890@c.us'
            && $r['text'] === 'Halo juga'
            && $r->hasHeader('X-Api-Key', 'kunci-uji'));
    }

    public function test_send_text_accepts_object_id(): void
    {
        Http::fake(['*' => Http::response(['id' => ['_serialized' => 'true_x@c.us_1']])]);

        $this->assertSame('true_x@c.us_1', $this->driver()->sendText('6281234567890', 'x'));
    }

    public function test_send_text_throws_when_waha_rejects(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Session not ready'], 422)]);

        $this->expectException(WhatsAppSendException::class);
        $this->expectExceptionMessage('HTTP 422');
        $this->driver()->sendText('6281234567890', 'x');
    }

    public function test_send_text_throws_when_waha_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(WhatsAppSendException::class);
        $this->expectExceptionMessage('tidak terjangkau');
        $this->driver()->sendText('6281234567890', 'x');
    }
}
