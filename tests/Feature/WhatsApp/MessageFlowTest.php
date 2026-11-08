<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\WhatsApp\ReceiveWhatsAppMessage;
use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Client;
use App\Models\User;
use App\Models\WaMessage;
use App\Services\WhatsApp\IncomingMessage;
use App\Services\WhatsApp\WhatsAppProvider;
use App\Services\WhatsApp\WhatsAppSendException;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['koedu.whatsapp.driver' => 'waha', 'koedu.whatsapp.waha.url' => 'http://waha:3000']);
    }

    private function incoming(string $id = 'm1', string $phone = '6281234567890'): IncomingMessage
    {
        return new IncomingMessage($id, $phone, 'Budi', 'text', 'Halo', CarbonImmutable::parse('2026-11-08 10:00'), 'waha');
    }

    public function test_incoming_message_creates_conversation_and_links_client(): void
    {
        $client = Client::factory()->create(['phone' => '081234567890']); // disimpan sebagai 6281234567890

        $message = app(ReceiveWhatsAppMessage::class)->handle($this->incoming());

        $conversation = $message->conversation;
        $this->assertSame($client->id, $conversation->client_id);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('2026-11-08 10:00', $conversation->last_message_at->format('Y-m-d H:i'));
        $this->assertSame(MessageStatus::Received, $message->status);
    }

    public function test_duplicate_webhook_is_stored_once(): void
    {
        $receive = app(ReceiveWhatsAppMessage::class);

        $this->assertNotNull($receive->handle($this->incoming('dup')));
        $this->assertNull($receive->handle($this->incoming('dup')));

        $this->assertSame(1, WaMessage::count());
        $this->assertSame(1, WaMessage::first()->conversation->unread_count);
    }

    public function test_second_message_increments_unread_in_same_conversation(): void
    {
        $receive = app(ReceiveWhatsAppMessage::class);
        $receive->handle($this->incoming('a'));
        $second = $receive->handle($this->incoming('b'));

        $this->assertSame(2, $second->conversation->refresh()->unread_count);
        $this->assertSame(1, \App\Models\WaConversation::count());
    }

    public function test_conversation_gets_linked_when_client_registered_later(): void
    {
        $receive = app(ReceiveWhatsAppMessage::class);
        $conversation = $receive->handle($this->incoming('a'))->conversation;
        $this->assertNull($conversation->client_id);

        $client = Client::factory()->create(['phone' => '6281234567890']);
        $receive->handle($this->incoming('b'));

        $this->assertSame($client->id, $conversation->refresh()->client_id);
    }

    public function test_send_queues_message_and_dispatches_job_on_whatsapp_queue(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $message = app(SendWhatsAppMessage::class)->handle('0812-3456-7890', 'Jadwal Anda besok', $admin);

        $this->assertSame(MessageStatus::Queued, $message->status);
        $this->assertSame('6281234567890', $message->conversation->phone);
        $this->assertSame($admin->id, $message->sent_by);
        Queue::assertPushedOn('whatsapp', SendWhatsAppMessageJob::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_job_sends_and_marks_message_sent(): void
    {
        Queue::fake();
        Http::fake(['waha:3000/api/sendText' => Http::response(['id' => 'true_x_1'])]);
        $message = app(SendWhatsAppMessage::class)->handle('6281234567890', 'Halo');

        (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppProvider::class));

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('true_x_1', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
    }

    public function test_job_does_not_send_twice(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['id' => 'true_x_1'])]);
        $message = app(SendWhatsAppMessage::class)->handle('6281234567890', 'Halo');
        $job = new SendWhatsAppMessageJob($message->id);

        $job->handle(app(WhatsAppProvider::class));
        $job->handle(app(WhatsAppProvider::class));

        Http::assertSentCount(1);
    }

    public function test_failed_send_stays_queued_for_retry_then_is_marked_failed(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['message' => 'Session not ready'], 422)]);
        $message = app(SendWhatsAppMessage::class)->handle('6281234567890', 'Halo');
        $job = new SendWhatsAppMessageJob($message->id);

        try {
            $job->handle(app(WhatsAppProvider::class));
            $this->fail('Seharusnya melempar exception supaya antrean mencoba ulang.');
        } catch (WhatsAppSendException) {
        }
        $this->assertSame(MessageStatus::Queued, $message->refresh()->status, 'masih antre untuk percobaan berikutnya');

        $job->failed(new Exception('WAHA menolak pengiriman'));

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('WAHA menolak', $message->error);
    }

    public function test_unknown_driver_fails_loudly(): void
    {
        config(['koedu.whatsapp.driver' => 'telegram']);

        $this->expectException(\InvalidArgumentException::class);
        app(WhatsAppProvider::class);
    }

    public function test_driver_is_switched_by_config(): void
    {
        $this->assertSame('waha', app(WhatsAppProvider::class)->name());

        config(['koedu.whatsapp.driver' => 'cloud_api']);
        $this->assertSame('cloud_api', app(WhatsAppProvider::class)->name());
    }
}
