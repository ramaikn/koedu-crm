<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): WaConversation
    {
        $conversation = WaConversation::create(['phone' => '6281234567890', 'display_name' => 'Budi', 'unread_count' => 2, 'last_message_at' => now()]);
        $conversation->messages()->create([
            'direction' => MessageDirection::Inbound, 'body' => 'Halo', 'status' => MessageStatus::Received, 'provider' => 'waha',
        ]);

        return $conversation;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/inbox')->assertRedirect('/login');
    }

    public function test_tutor_cannot_open_inbox(): void
    {
        $conversation = $this->conversation();
        $tutor = User::factory()->tutor()->create();

        $this->actingAs($tutor)->get('/inbox')->assertForbidden();
        $this->actingAs($tutor)->get("/inbox/{$conversation->id}")->assertForbidden();
        $this->actingAs($tutor)->post("/inbox/{$conversation->id}/messages", ['body' => 'x'])->assertForbidden();
    }

    public function test_admin_sees_conversations_and_opening_marks_them_read(): void
    {
        $conversation = $this->conversation();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/inbox/{$conversation->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inbox/index')
                ->where('can_reply', true)
                ->has('conversations', 1)
                ->where('active.name', 'Budi')
                ->has('active.messages', 1));

        $this->assertSame(0, $conversation->refresh()->unread_count);
    }

    public function test_director_can_read_but_does_not_clear_unread_or_reply(): void
    {
        Queue::fake();
        $conversation = $this->conversation();
        $director = User::factory()->director()->create();

        $this->actingAs($director)->get("/inbox/{$conversation->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_reply', false));
        $this->assertSame(2, $conversation->refresh()->unread_count);

        $this->actingAs($director)->post("/inbox/{$conversation->id}/messages", ['body' => 'Halo'])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_admin_reply_is_queued_for_sending(): void
    {
        Queue::fake();
        $conversation = $this->conversation();

        $this->actingAs(User::factory()->admin()->create())
            ->post("/inbox/{$conversation->id}/messages", ['body' => '  Halo Kak, ada yang bisa dibantu?  '])
            ->assertRedirect();

        $reply = $conversation->messages()->where('direction', MessageDirection::Outbound)->first();
        $this->assertSame('Halo Kak, ada yang bisa dibantu?', $reply->body);
        $this->assertSame(MessageStatus::Queued, $reply->status);
        Queue::assertPushedOn('whatsapp', SendWhatsAppMessageJob::class);
    }

    public function test_reply_must_not_be_empty_or_too_long(): void
    {
        Queue::fake();
        $conversation = $this->conversation();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post("/inbox/{$conversation->id}/messages", ['body' => ''])->assertSessionHasErrors('body');
        $this->actingAs($admin)->post("/inbox/{$conversation->id}/messages", ['body' => str_repeat('a', 4001)])->assertSessionHasErrors('body');
        Queue::assertNothingPushed();
    }
}
