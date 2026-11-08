<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function wahaPayload(string $id = 'w1'): array
    {
        return ['event' => 'message', 'payload' => [
            'id' => $id, 'timestamp' => 1_762_600_000, 'from' => '6281234567890@c.us',
            'fromMe' => false, 'body' => 'Halo', 'hasMedia' => false,
        ]];
    }

    public function test_n8n_endpoint_rejects_missing_or_wrong_token(): void
    {
        config(['koedu.n8n.api_token' => 'token-rahasia', 'koedu.whatsapp.driver' => 'waha']);

        $this->postJson('/api/n8n/whatsapp/incoming', $this->wahaPayload())->assertUnauthorized();
        $this->withHeader('X-Koedu-Token', 'salah')->postJson('/api/n8n/whatsapp/incoming', $this->wahaPayload())->assertUnauthorized();

        $this->assertSame(0, WaMessage::count());
    }

    public function test_n8n_endpoint_is_disabled_when_token_not_configured(): void
    {
        config(['koedu.n8n.api_token' => '']);

        $this->withHeader('X-Koedu-Token', '')->postJson('/api/n8n/whatsapp/incoming', $this->wahaPayload())->assertUnauthorized();
    }

    public function test_n8n_endpoint_stores_message_and_reports_counts(): void
    {
        config(['koedu.n8n.api_token' => 'token-rahasia', 'koedu.whatsapp.driver' => 'waha']);
        $headers = ['X-Koedu-Token' => 'token-rahasia'];

        $this->withHeaders($headers)->postJson('/api/n8n/whatsapp/incoming', $this->wahaPayload())
            ->assertOk()->assertJson(['received' => 1, 'stored' => 1]);
        $this->withHeaders($headers)->postJson('/api/n8n/whatsapp/incoming', $this->wahaPayload())
            ->assertOk()->assertJson(['received' => 1, 'stored' => 0]);

        $this->assertSame(1, WaMessage::count());
    }

    public function test_cloud_webhook_is_not_found_when_driver_is_not_cloud_api(): void
    {
        config(['koedu.whatsapp.driver' => 'waha']);

        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe')->assertNotFound();
        $this->postJson('/api/webhooks/whatsapp', [])->assertNotFound();
    }

    public function test_cloud_webhook_verification_handshake(): void
    {
        config(['koedu.whatsapp.driver' => 'cloud_api', 'koedu.whatsapp.cloud_api.verify_token' => 'verify-uji']);

        // Meta mengirim "hub.mode"; PHP mengubah titik pada nama query menjadi garis bawah.
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-uji&hub.challenge=987')
            ->assertOk()->assertSee('987');
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=salah&hub.challenge=987')
            ->assertForbidden();
    }

    public function test_cloud_webhook_requires_valid_signature(): void
    {
        config(['koedu.whatsapp.driver' => 'cloud_api', 'koedu.whatsapp.cloud_api.app_secret' => 'rahasia-app']);
        $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [
            ['id' => 'wamid.9', 'from' => '6281234567890', 'timestamp' => '1762600000', 'type' => 'text', 'text' => ['body' => 'Halo']],
        ]]]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=salah'], $body)
            ->assertForbidden();
        $this->assertSame(0, WaMessage::count());

        $signature = 'sha256='.hash_hmac('sha256', $body, 'rahasia-app');
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $body)
            ->assertOk();
        $this->assertSame(1, WaMessage::count());
    }
}
