<?php

namespace Tests\Feature\Notifications;

use App\Enums\MessageDirection;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Tutor;
use App\Models\WaMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRunResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pages\PageTestCase;

/**
 * Dasar test notifikasi. Waktu "sekarang" digeser dengan travelTo ke Senin 2026-11-09 supaya hasilnya tetap
 * sama kapan pun test dijalankan. Antrean dipalsukan: pesan tercatat di wa_messages tetapi tidak dikirim ke WA.
 */
abstract class NotificationTestCase extends PageTestCase
{
    protected const TUESDAY = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'koedu.notifications.enabled' => true,
            'koedu.notifications.allowed_phones' => [],
            'koedu.notifications.admin_phones' => [],
            'koedu.notifications.window_start' => '07:00',
            'koedu.notifications.window_end' => '21:00',
            'koedu.notifications.urgent_hours' => 12,
            'koedu.notifications.day_reminder_time' => '18:00',
            'koedu.notifications.hour_reminder_minutes' => 60,
            'koedu.notifications.min_lead_minutes' => 10,
            'koedu.notifications.digest_time' => '08:00',
            'koedu.notifications.sender_name' => 'Tim Bimbingan',
        ]);
    }

    /** Geser waktu sekarang. */
    protected function at_(string $time, string $date = self::MONDAY): CarbonImmutable
    {
        $moment = $this->at($time, $date);
        $this->travelTo($moment);

        return $moment;
    }

    protected function run_(): NotificationRunResult
    {
        return app(NotificationDispatcher::class)->run();
    }

    /** Sesi terjadwal dengan tanggal dan jam bebas. */
    protected function sessionAt(Tutor $tutor, string $date, string $time, array $clients = [], int $minutes = 60, array $attributes = []): Consultation
    {
        $start = $this->at($time, $date);

        return $this->makeSession($tutor, clients: $clients, attributes: array_merge([
            'title' => 'Bimbingan Bab 2',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($minutes),
            'meeting_url' => 'https://zoom.us/j/123',
        ], $attributes));
    }

    protected function person(string $name): Client
    {
        return Client::factory()->create(['name' => $name]);
    }

    protected function teacher(string $name): Tutor
    {
        return Tutor::factory()->create(['name' => $name]);
    }

    /** Semua pesan keluar yang tercatat, terbaru di belakang. */
    protected function outbound(): Collection
    {
        return WaMessage::with('conversation')->where('direction', MessageDirection::Outbound)->orderBy('id')->get();
    }

    protected function bodiesTo(string $phone): array
    {
        return $this->outbound()->filter(fn (WaMessage $m) => $m->conversation->phone === $phone)->pluck('body')->values()->all();
    }

    protected function assertNothingSent(string $message = ''): void
    {
        $this->assertCount(0, $this->outbound(), $message ?: 'tidak ada pesan yang seharusnya terkirim');
    }

    protected function assertQueuedJobs(int $count): void
    {
        Queue::assertPushed(SendWhatsAppMessageJob::class, $count);
    }
}
