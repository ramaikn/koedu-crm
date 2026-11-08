<?php

namespace Tests\Feature\Ai;

use App\Actions\WhatsApp\ReceiveWhatsAppMessage;
use App\Enums\DraftStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\ExtractScheduleDraftJob;
use App\Models\Consultation;
use App\Models\ScheduleDraft;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiUnavailableException;
use App\Services\WhatsApp\IncomingMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pages\PageTestCase;

class DraftFlowTest extends PageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // AI mati di phpunit.xml. Di sini dinyalakan dan diarahkan ke provider palsu.
        config(['koedu.ai.enabled' => true, 'koedu.ai.providers' => ['gemini'], 'koedu.ai.gemini.keys' => ['k'], 'koedu.ai.extraction.min_confidence' => 0.6]);
    }

    private function fakeAi(array|\Closure $answer): FakeAiProvider
    {
        $fake = new FakeAiProvider($answer);
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    private function inbound(string $body = 'Mau jadwal olah data Sabtu jam 10 ya', ?WaConversation $conversation = null, string $id = null): WaMessage
    {
        $conversation ??= WaConversation::create(['phone' => '6281234567890', 'display_name' => 'Budi WA']);

        return $conversation->messages()->create([
            'direction' => MessageDirection::Inbound, 'type' => 'text', 'body' => $body, 'status' => MessageStatus::Received,
            'provider' => 'waha', 'provider_message_id' => $id ?? uniqid('m'), 'sent_at' => now(),
        ]);
    }

    private function runJob(WaMessage $message): void
    {
        (new ExtractScheduleDraftJob($message->id))->handle(app(\App\Services\Scheduling\ScheduleRequestExtractor::class), app(\App\Actions\Scheduling\UpsertScheduleDraft::class));
    }

    // --- Job: chat menjadi draf ---

    public function test_schedule_request_creates_a_pending_draft(): void
    {
        $client = \App\Models\Client::factory()->create(['phone' => '6281234567890']);
        $this->fakeAi(FakeAiProvider::scheduleRequest());
        $message = $this->inbound(conversation: WaConversation::create(['phone' => '6281234567890', 'client_id' => $client->id]));

        $this->runJob($message);

        $draft = ScheduleDraft::sole();
        $this->assertSame(DraftStatus::Pending, $draft->status);
        $this->assertSame($client->id, $draft->client_id);
        $this->assertSame($message->id, $draft->source_message_id);
        $this->assertSame('fake', $draft->ai_provider);
        $this->assertSame('10:00', $draft->extracted['requested_time']);
        $this->assertSame('Olah data SmartPLS', $draft->extracted['service_note']);
        $this->assertSame(0.92, $draft->extracted['confidence']);
    }

    public function test_non_requests_and_low_confidence_create_no_draft(): void
    {
        $message = $this->inbound('Berapa harga paket skripsi?');

        $this->fakeAi(FakeAiProvider::scheduleRequest(['is_schedule_request' => false, 'confidence' => 0.95]));
        $this->runJob($message);

        $this->fakeAi(FakeAiProvider::scheduleRequest(['confidence' => 0.4]));
        $this->runJob($message);

        $this->assertSame(0, ScheduleDraft::count());
    }

    public function test_follow_up_message_completes_the_pending_draft_instead_of_creating_another(): void
    {
        $conversation = WaConversation::create(['phone' => '6281234567890']);
        $date = now('Asia/Makassar')->addDays(3)->toDateString();

        $this->fakeAi(FakeAiProvider::scheduleRequest(['requested_date' => $date, 'requested_time' => null, 'service_note' => 'Bab 2']));
        $first = $this->inbound('Mau bimbingan bab 2', $conversation);
        $this->runJob($first);

        $this->fakeAi(FakeAiProvider::scheduleRequest(['requested_date' => null, 'requested_time' => '14:00', 'service_note' => null, 'summary' => 'Jam 2 siang']));
        $second = $this->inbound('Jam 2 siang aja kak', $conversation);
        $this->runJob($second);

        $draft = ScheduleDraft::sole();
        $this->assertSame($date, $draft->extracted['requested_date'], 'rincian lama dipertahankan');
        $this->assertSame('14:00', $draft->extracted['requested_time'], 'rincian baru ditambahkan');
        $this->assertSame('Bab 2', $draft->extracted['service_note']);
        $this->assertSame('Jam 2 siang', $draft->extracted['summary']);
        $this->assertSame([$first->id, $second->id], $draft->extracted['source_message_ids']);
    }

    public function test_processed_drafts_do_not_absorb_new_requests(): void
    {
        $conversation = WaConversation::create(['phone' => '6281234567890']);
        $this->fakeAi(FakeAiProvider::scheduleRequest());
        $this->runJob($this->inbound('Jadwal pertama', $conversation));
        ScheduleDraft::sole()->update(['status' => DraftStatus::Approved]);

        $this->runJob($this->inbound('Mau jadwal lagi minggu depan', $conversation));

        $this->assertSame(2, ScheduleDraft::count());
        $this->assertSame(1, ScheduleDraft::pending()->count());
    }

    public function test_job_skips_outbound_short_and_missing_messages_without_calling_ai(): void
    {
        $fake = $this->fakeAi(FakeAiProvider::scheduleRequest());
        $conversation = WaConversation::create(['phone' => '6281234567890']);
        $outbound = $conversation->messages()->create(['direction' => MessageDirection::Outbound, 'body' => 'Silakan jadwal kapan saja', 'status' => MessageStatus::Sent, 'provider' => 'waha']);

        $this->runJob($outbound);
        $this->runJob($this->inbound('ok', $conversation));
        (new ExtractScheduleDraftJob(999_999))->handle(app(\App\Services\Scheduling\ScheduleRequestExtractor::class), app(\App\Actions\Scheduling\UpsertScheduleDraft::class));

        $this->assertSame([], $fake->calls);
    }

    public function test_job_does_nothing_when_ai_is_disabled(): void
    {
        $fake = $this->fakeAi(FakeAiProvider::scheduleRequest());
        config(['koedu.ai.gemini.keys' => []]);

        $this->runJob($this->inbound());

        $this->assertSame([], $fake->calls);
        $this->assertSame(0, ScheduleDraft::count());
    }

    public function test_ai_failure_propagates_for_retry_and_leaves_no_draft(): void
    {
        $this->fakeAi(fn () => throw new AiUnavailableException('semua key limit'));
        $message = $this->inbound();

        try {
            $this->runJob($message);
            $this->fail('Seharusnya melempar supaya antrean mencoba ulang.');
        } catch (AiUnavailableException) {
        }

        $this->assertSame(0, ScheduleDraft::count());
        $this->assertSame(1, WaMessage::count(), 'pesan chat tetap tersimpan');
        (new ExtractScheduleDraftJob($message->id))->failed(new AiUnavailableException('x')); // hanya mencatat log, tidak error
    }

    public function test_hourly_cap_per_conversation_protects_cost(): void
    {
        config(['koedu.ai.extraction.max_calls_per_hour' => 2]);
        $fake = $this->fakeAi(FakeAiProvider::scheduleRequest(['is_schedule_request' => false]));
        $conversation = WaConversation::create(['phone' => '6281234567890']);

        foreach (range(1, 4) as $i) {
            $this->runJob($this->inbound("Pesan nomor {$i} dari client", $conversation));
        }

        $this->assertCount(2, $fake->calls);
    }

    // --- Pemicu dari chat masuk ---

    private function incoming(string $type = 'text', string $id = 'in-1'): IncomingMessage
    {
        return new IncomingMessage($id, '6281234567890', 'Budi', $type, $type === 'text' ? 'Mau jadwal ya' : '[Pesan media]', CarbonImmutable::now('Asia/Makassar'), 'waha');
    }

    public function test_incoming_text_dispatches_the_ai_job_on_the_ai_queue(): void
    {
        Queue::fake();

        $message = app(ReceiveWhatsAppMessage::class)->handle($this->incoming());

        Queue::assertPushedOn('ai', ExtractScheduleDraftJob::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_media_duplicates_and_disabled_ai_do_not_dispatch(): void
    {
        Queue::fake();
        $receive = app(ReceiveWhatsAppMessage::class);

        $receive->handle($this->incoming('media', 'in-media'));
        $receive->handle($this->incoming('text', 'in-dup'));
        Queue::assertPushed(ExtractScheduleDraftJob::class, 1);

        $receive->handle($this->incoming('text', 'in-dup')); // duplikat
        Queue::assertPushed(ExtractScheduleDraftJob::class, 1);

        config(['koedu.ai.gemini.keys' => []]);
        $receive->handle($this->incoming('text', 'in-off'));
        Queue::assertPushed(ExtractScheduleDraftJob::class, 1);
    }

    // --- Persetujuan oleh admin ---

    private function draftFor(\App\Models\Client $client = null, array $extracted = []): ScheduleDraft
    {
        $conversation = WaConversation::create(['phone' => $client?->phone ?? '6281234567890', 'display_name' => 'Budi WA', 'client_id' => $client?->id]);
        $message = $this->inbound(conversation: $conversation);

        return ScheduleDraft::create([
            'conversation_id' => $conversation->id, 'source_message_id' => $message->id, 'client_id' => $client?->id, 'status' => DraftStatus::Pending,
            'extracted' => array_merge(FakeAiProvider::scheduleRequest(['requested_date' => $this->daysFromNow(2)]), ['source_message_ids' => [$message->id]], $extracted),
        ]);
    }

    public function test_schedule_form_is_prefilled_from_the_draft_and_finds_the_registered_client(): void
    {
        $client = $this->activeClient();
        $draft = $this->draftFor(null);
        $draft->conversation->update(['phone' => $client->phone]); // client didaftarkan setelah chat masuk

        $this->actingAs($this->admin())->get("/schedule/create?draft_id={$draft->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('schedule/form')
                ->where('draft.id', $draft->id)
                ->where('draft.client_registered', true)
                ->where('prefill.date', $this->daysFromNow(2))
                ->where('prefill.start_time', '10:00')
                ->where('prefill.duration_minutes', 60)
                ->where('prefill.topic', 'Olah data SmartPLS')
                ->where('preset_clients.0.id', $client->id));
    }

    public function test_unregistered_client_gets_registration_prefill_data(): void
    {
        $draft = $this->draftFor(null);

        $this->actingAs($this->admin())->get("/schedule/create?draft_id={$draft->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('draft.client_registered', false)
                ->where('draft.register.phone', '6281234567890')
                ->where('draft.register.name', 'Budi')
                ->has('preset_clients', 0));

        $this->actingAs($this->admin())->get('/clients/create?name=Budi&phone=6281234567890&degree_level=S1')
            ->assertInertia(fn (Assert $p) => $p->where('prefill.name', 'Budi')->where('prefill.phone', '6281234567890')->where('prefill.degree_level', 'S1'));
    }

    public function test_processed_or_unknown_draft_opens_an_empty_form(): void
    {
        $draft = $this->draftFor();
        $draft->update(['status' => DraftStatus::Rejected]);

        $this->actingAs($this->admin())->get("/schedule/create?draft_id={$draft->id}")->assertRedirect('/schedule/create');
        $this->actingAs($this->admin())->get('/schedule/create?draft_id=99999')->assertRedirect('/schedule/create');
    }

    public function test_creating_the_session_approves_the_draft(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $draft = $this->draftFor($client);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $client, ['schedule_draft_id' => $draft->id]))->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertSame(DraftStatus::Approved, $draft->status);
        $this->assertSame($admin->id, $draft->reviewed_by);
        $this->assertNotNull($draft->reviewed_at);
        $this->assertSame($draft->id, Consultation::sole()->schedule_draft_id);
        $this->assertSame($draft->id, $draft->consultation->schedule_draft_id);
    }

    public function test_a_draft_cannot_be_used_twice_and_failed_schedules_keep_it_pending(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $draft = $this->draftFor($client);
        $admin = $this->admin();

        // Jadwal ditolak karena bentrok: draf tetap menunggu.
        $this->makeSession($tutor, attributes: [
            'starts_at' => CarbonImmutable::parse($this->daysFromNow(1).' 10:00', 'Asia/Makassar'), 'ends_at' => CarbonImmutable::parse($this->daysFromNow(1).' 11:00', 'Asia/Makassar'),
        ]);
        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $client, ['schedule_draft_id' => $draft->id]))->assertSessionHasErrors('schedule');
        $this->assertSame(DraftStatus::Pending, $draft->refresh()->status);

        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $client, ['schedule_draft_id' => $draft->id, 'start_time' => '14:00']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post('/schedule', $this->scheduleForm($tutor, $this->activeClient(), ['schedule_draft_id' => $draft->id, 'start_time' => '16:00']))
            ->assertSessionHasErrors('schedule');

        $this->assertSame(2, Consultation::count(), 'sesi bentrok awal + satu sesi dari draf');
    }

    public function test_admin_rejects_a_draft_and_others_cannot(): void
    {
        $draft = $this->draftFor();
        $admin = $this->admin();

        $this->actingAs($this->director())->post("/schedule-drafts/{$draft->id}/reject")->assertForbidden();
        $this->actingAs($this->anyDayTutor()->user)->post("/schedule-drafts/{$draft->id}/reject")->assertForbidden();

        $this->actingAs($admin)->post("/schedule-drafts/{$draft->id}/reject", ['reason' => 'Bukan permintaan jadwal'])->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame(DraftStatus::Rejected, $draft->status);
        $this->assertSame('Bukan permintaan jadwal', $draft->reject_reason);
        $this->assertSame($admin->id, $draft->reviewed_by);

        $this->actingAs($admin)->post("/schedule-drafts/{$draft->id}/reject")->assertSessionHasErrors('draft');
    }

    public function test_inbox_and_dashboard_surface_pending_drafts(): void
    {
        $draft = $this->draftFor();
        $draft->conversation->update(['last_message_at' => now()]);
        $other = WaConversation::create(['phone' => '6289999999999', 'last_message_at' => now()->subHour()]);

        $this->actingAs($this->admin())->get("/inbox/{$draft->conversation_id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('active.draft.id', $draft->id)
                ->where('active.draft.fields.requested_time', '10:00')
                ->where('active.draft.client_registered', false)
                ->where('conversations.0.has_draft', true));

        $this->actingAs($this->admin())->get("/inbox/{$other->id}")->assertInertia(fn (Assert $p) => $p->where('active.draft', null));
        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('stats.pending_drafts', 1));

        $draft->update(['status' => DraftStatus::Approved]);
        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('stats.pending_drafts', 0));
    }
}
