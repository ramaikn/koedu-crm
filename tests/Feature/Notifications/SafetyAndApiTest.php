<?php

namespace Tests\Feature\Notifications;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Notifications\NotificationRunResult;
use App\Services\Notifications\Rules\TutorSessionNotices;
use App\Services\WhatsApp\WahaDriver;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class SafetyAndApiTest extends NotificationTestCase
{
    // --- Jam kirim ---

    public function test_non_urgent_messages_wait_for_the_morning_but_urgent_ones_do_not(): void
    {
        $this->at_('22:00');
        $client = $this->person('Budi');
        $this->sessionAt($this->teacher('Dewi'), '2026-11-11', '10:00', [$client]); // 36 jam lagi: tidak mendesak
        $urgent = $this->person('Siti');
        $this->sessionAt($this->teacher('Rani'), self::TUESDAY, '06:00', [$urgent]); // 8 jam lagi: mendesak

        $result = $this->run_();

        $this->assertFalse($result->windowOpen);
        $this->assertSame(2, $result->total(NotificationRunResult::SENT), 'tutor dan client sesi mendesak');
        $this->assertSame(2, $result->total(NotificationRunResult::DEFERRED));
        $this->assertSame([], $this->bodiesTo($client->phone));
        $this->assertCount(1, $this->bodiesTo($urgent->phone));

        $this->at_('06:59', self::TUESDAY);
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT), 'jendela belum terbuka');

        $this->at_('07:00', self::TUESDAY);
        $this->assertSame(2, $this->run_()->total(NotificationRunResult::SENT), 'tertunda dikirim begitu jendela terbuka');
        $this->assertCount(1, $this->bodiesTo($client->phone));
    }

    // --- Pengaman ---

    public function test_when_disabled_nothing_runs_or_is_claimed(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$this->person('Budi')]);
        config(['koedu.notifications.enabled' => false]);

        $result = $this->run_();

        $this->assertFalse($result->enabled);
        $this->assertSame([], $result->counts());
        $this->assertNothingSent();
        $this->assertNull($session->refresh()->tutor_notified_at);
    }

    public function test_allowlist_limits_recipients_and_leaves_others_unclaimed(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi');
        $client = $this->person('Budi');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$client]);
        config(['koedu.notifications.allowed_phones' => [$client->phone]]);

        $result = $this->run_();

        $this->assertSame(1, $result->total(NotificationRunResult::SENT));
        $this->assertSame(1, $result->total(NotificationRunResult::NOT_ALLOWED));
        $this->assertSame([], $this->bodiesTo($tutor->phone));
        $this->assertNull($session->refresh()->tutor_notified_at, 'tidak diklaim, jadi terkirim begitu nomornya diizinkan');

        config(['koedu.notifications.allowed_phones' => [$tutor->phone, '0'.substr($client->phone, 2)]]); // format lokal juga cocok
        $this->assertSame(1, $this->run_()->total(NotificationRunResult::SENT));
        $this->assertCount(1, $this->bodiesTo($tutor->phone));
    }

    public function test_dry_run_shows_the_plan_without_sending_or_claiming(): void
    {
        $this->at_('10:00');
        $session = $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$this->person('Budi')]);
        config(['koedu.notifications.enabled' => false]); // mode uji jalan walau notifikasi mati

        $result = app(\App\Services\Notifications\NotificationDispatcher::class)->run(dryRun: true);

        $this->assertCount(2, $result->previews());
        $this->assertSame(NotificationRunResult::PLANNED, $result->previews()[0]['status']);
        $this->assertStringStartsWith('...', $result->previews()[0]['phone'], 'nomor disamarkan');
        $this->assertNothingSent();
        $this->assertNull($session->refresh()->tutor_notified_at);
    }

    public function test_a_claim_succeeds_for_only_one_caller(): void
    {
        $this->at_('10:00');
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$this->person('Budi')]);

        $planned = app(TutorSessionNotices::class)->plan(\Carbon\CarbonImmutable::now('Asia/Makassar'));

        $this->assertTrue(($planned[0]->claim)());
        $this->assertFalse(($planned[0]->claim)(), 'proses kedua tidak boleh mengirim pesan yang sama');
    }

    public function test_a_claim_is_undone_when_queueing_the_message_fails(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', []);
        // SendWhatsAppMessage berkelas final (tidak bisa di-mock), jadi kegagalan dibuat lewat penyedia WA yang melempar error.
        $this->app->bind(WhatsAppProvider::class, fn () => new class implements WhatsAppProvider {
            public function name(): string { throw new RuntimeException('antrean mati'); }
            public function sendText(string $phone, string $text): ?string { return null; }
            public function parseWebhook(array $payload): array { return []; }
        });

        $result = $this->run_();

        $this->assertSame(1, $result->total(NotificationRunResult::ERROR));
        $this->assertNull($session->refresh()->tutor_notified_at, 'klaim dibatalkan, dicoba lagi di putaran berikutnya');

        // Penyedia normal kembali: klaim yang dibatalkan membuat pesan terkirim di putaran berikutnya.
        $this->app->bind(WhatsAppProvider::class, fn () => new WahaDriver('http://waha:3000', 'default', null));
        $this->assertSame(1, $this->run_()->total(NotificationRunResult::SENT));
    }

    // --- API untuk n8n ---

    public function test_api_requires_the_n8n_token(): void
    {
        config(['koedu.n8n.api_token' => 'rahasia']);

        $this->postJson('/api/n8n/notifications/run')->assertUnauthorized();
        $this->withHeader('X-Koedu-Token', 'salah')->postJson('/api/n8n/notifications/run')->assertUnauthorized();
    }

    public function test_api_runs_notifications_and_reports_counts(): void
    {
        config(['koedu.n8n.api_token' => 'rahasia']);
        $this->at_('10:00');
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$this->person('Budi')]);
        WaMessage::create(['conversation_id' => WaConversation::create(['phone' => '6281'])->id, 'direction' => MessageDirection::Outbound, 'status' => MessageStatus::Failed, 'provider' => 'waha', 'body' => 'x']);

        $this->withHeader('X-Koedu-Token', 'rahasia')->postJson('/api/n8n/notifications/run')
            ->assertOk()
            ->assertJson(['enabled' => true, 'window_open' => true, 'sent' => 2, 'errors' => 0, 'failed_recent' => 1])
            ->assertJsonPath('by_type.tutor_new.terkirim', 1)
            ->assertJsonMissingPath('previews');
    }

    public function test_api_skips_when_a_previous_run_is_still_going(): void
    {
        config(['koedu.n8n.api_token' => 'rahasia']);
        $this->at_('10:00');
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$this->person('Budi')]);
        $lock = Cache::lock('notifications:run', 60);
        $this->assertTrue($lock->get());

        $this->withHeader('X-Koedu-Token', 'rahasia')->postJson('/api/n8n/notifications/run')->assertOk()->assertJsonStructure(['skipped']);
        $this->assertNothingSent();

        $lock->release();
        $this->withHeader('X-Koedu-Token', 'rahasia')->postJson('/api/n8n/notifications/run')->assertJson(['sent' => 2]);
    }

    // --- Perintah artisan ---

    public function test_command_dry_run_prints_messages_and_changes_nothing(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $this->sessionAt($tutor, self::TUESDAY, '14:00', [$this->person('Budi')]);

        $this->artisan('notifications:run', ['--dry-run' => true])
            ->expectsOutputToContain('akan dikirim')
            ->expectsOutputToContain('ada jadwal bimbingan baru untuk Anda')
            ->assertSuccessful();

        $this->assertNothingSent();
    }

    public function test_command_reports_when_notifications_are_off(): void
    {
        config(['koedu.notifications.enabled' => false]);

        $this->artisan('notifications:run')->expectsOutputToContain('Notifikasi mati')->assertSuccessful();
    }

    // --- Kirim ulang pesan gagal ---

    public function test_admin_can_retry_a_failed_message(): void
    {
        $conversation = WaConversation::create(['phone' => '6281234567890']);
        $failed = WaMessage::create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Outbound, 'status' => MessageStatus::Failed, 'provider' => 'waha', 'body' => 'Halo', 'error' => 'WAHA mati']);

        $this->actingAs($this->director())->post("/inbox/messages/{$failed->id}/retry")->assertForbidden();
        $this->actingAs($this->anyDayTutor()->user)->post("/inbox/messages/{$failed->id}/retry")->assertForbidden();

        $this->actingAs($this->admin())->post("/inbox/messages/{$failed->id}/retry")->assertSessionHasNoErrors();

        $failed->refresh();
        $this->assertSame(MessageStatus::Queued, $failed->status);
        $this->assertNull($failed->error);
        Queue::assertPushedOn('whatsapp', SendWhatsAppMessageJob::class, fn ($job) => $job->messageId === $failed->id);

        $this->actingAs($this->admin())->post("/inbox/messages/{$failed->id}/retry")->assertSessionHasErrors('message');
    }

    public function test_dashboard_and_schedule_pages_expose_notification_state(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$this->person('Budi')]);
        $this->run_();
        WaMessage::create(['conversation_id' => WaConversation::create(['phone' => '6281'])->id, 'direction' => MessageDirection::Outbound, 'status' => MessageStatus::Failed, 'provider' => 'waha', 'body' => 'x']);

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('stats.failed_messages', 1));
        $this->actingAs($this->admin())->get("/schedule/{$session->id}")
            ->assertInertia(fn ($page) => $page
                ->where('consultation.tutor_notified_at', fn ($v) => $v !== null)
                ->where('consultation.tutor_reminded_at', null)
                ->where('participants.0.confirmation_sent_at', fn ($v) => $v !== null)
                ->where('participants.0.reminder_hour_sent_at', null));
    }
}
