<?php

namespace Tests\Feature\Sheets;

use App\Jobs\SyncSheetTabJob;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\Tutor;
use App\Services\Sheets\SheetSyncService;
use App\Services\Sheets\SheetSyncStatus;
use App\Services\Sheets\SheetTabs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class SyncAndTriggersTest extends SheetsTestCase
{
    private function jobsFor(string $tab): int
    {
        return Queue::pushed(SyncSheetTabJob::class, fn (SyncSheetTabJob $job) => $job->tab === $tab)->count();
    }

    private function resetQueue(): void
    {
        Queue::fake(); // mengosongkan catatan dispatch dari pembuatan data uji
    }

    // --- Sinkron satu tab ---

    public function test_sync_writes_the_tab_records_status_and_marks_sessions_without_touching_updated_at(): void
    {
        $this->at_('10:00');
        $session = $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$this->person('Budi')]);
        $updatedAt = $session->refresh()->updated_at->toDateTimeString();
        $this->travelTo($this->at('11:30'));
        $this->fakeGoogle();

        $rows = app(SheetSyncService::class)->sync('jadwal');

        $this->assertSame(1, $rows);
        $write = collect($this->sheetsRequests())->first(fn ($r) => str_contains($r->url(), 'values:batchUpdate'));
        $this->assertStringContainsString('salinan otomatis, jangan diedit', $write['data'][0]['values'][0][0]);
        $this->assertStringContainsString('Koedu CRM', $write['data'][0]['values'][0][0]);
        $this->assertSame('Judul', $write['data'][0]['values'][1][5]);
        $this->assertSame($session->id, $write['data'][1]['values'][0][0]);

        $session->refresh();
        $this->assertNotNull($session->sheet_synced_at);
        $this->assertSame($updatedAt, $session->updated_at->toDateTimeString(), 'penandaan tersalin tidak mengubah updated_at (kalau tidak, sinkron memicu dirinya sendiri)');

        $status = collect(app(SheetSyncStatus::class)->summary())->firstWhere('tab', 'jadwal');
        $this->assertNotNull($status['synced_at']);
        $this->assertSame(1, $status['rows']);
        $this->assertNull($status['error']);
    }

    public function test_sync_with_no_rows_still_clears_the_old_data(): void
    {
        $this->fakeGoogle();

        $this->assertSame(0, app(SheetSyncService::class)->sync('client'));

        $this->assertStringContainsString('values:batchClear', $this->sheetsRequests()[1]->url());
    }

    public function test_transient_failure_is_recorded_and_rethrown_for_retry(): void
    {
        $this->googleFailure = 503;
        $this->fakeGoogle();

        try {
            app(SheetSyncService::class)->sync('jadwal');
            $this->fail('Seharusnya gagal.');
        } catch (\App\Services\Sheets\SheetsTransientException) {
        }

        $this->assertSame('transient', collect(app(SheetSyncStatus::class)->summary())->firstWhere('tab', 'jadwal')['error']);
    }

    public function test_configuration_failure_is_recorded_and_the_job_gives_up_instead_of_retrying(): void
    {
        $this->googleFailure = 403;
        $this->fakeGoogle();

        $job = (new SyncSheetTabJob('jadwal'))->withFakeQueueInteractions();
        $job->handle(app(SheetSyncService::class));

        $job->assertFailed();
        $this->assertSame('config', collect(app(SheetSyncStatus::class)->summary())->firstWhere('tab', 'jadwal')['error']);
    }

    public function test_a_later_success_clears_the_error(): void
    {
        $this->googleFailure = 503;
        $this->fakeGoogle();
        try {
            app(SheetSyncService::class)->sync('client');
        } catch (\Throwable) {
        }

        $this->googleFailure = null;
        app(SheetSyncService::class)->sync('client');

        $this->assertNull(collect(app(SheetSyncStatus::class)->summary())->firstWhere('tab', 'client')['error']);
    }

    public function test_job_is_unique_per_tab_and_skips_when_sheets_are_disabled(): void
    {
        $this->assertSame('jadwal', (new SyncSheetTabJob('jadwal'))->uniqueId());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing::class, new SyncSheetTabJob('jadwal'));
        $this->assertSame([30, 120, 300], (new SyncSheetTabJob('jadwal'))->backoff());

        config(['koedu.sheets.enabled' => false]);
        $this->fakeGoogle();
        (new SyncSheetTabJob('jadwal'))->handle(app(SheetSyncService::class));

        $this->assertSame([], $this->sheetsRequests());
    }

    public function test_unknown_tab_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SheetTabs::make('rahasia');
    }

    // --- Pemicu dari perubahan data ---

    public function test_changing_a_consultation_schedules_a_delayed_jadwal_sync_on_the_sheets_queue(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi');
        $this->resetQueue();

        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00');

        Queue::assertPushedOn('sheets', SyncSheetTabJob::class, fn (SyncSheetTabJob $job) => $job->tab === 'jadwal' && $job->delay !== null);
        $this->assertSame(0, $this->jobsFor('client'));

        $this->resetQueue();
        $session->update(['title' => 'Judul baru']);
        $this->assertSame(1, $this->jobsFor('jadwal'));
    }

    public function test_each_model_triggers_the_tabs_it_appears_in(): void
    {
        $this->at_('10:00');
        $client = $this->person('Budi');
        $tutor = $this->teacher('Dewi');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$client]);
        $enrollment = Enrollment::query()->first() ?? $this->activeClient()->enrollments()->first();

        $expectations = [
            'participant' => [fn () => $session->participants()->first()->update(['attendance' => 'present']), ['jadwal', 'client']],
            'client' => [fn () => $client->update(['university' => 'UNM']), ['client', 'jadwal', 'paket']],
            'enrollment' => [fn () => $enrollment->update(['notes' => 'x']), ['paket', 'client']],
            'quota' => [fn () => $enrollment->quotas()->first()?->update(['used' => 1]), ['paket']],
            'tutor' => [fn () => $tutor->update(['name' => 'Dewi Baru']), ['jadwal', 'paket']],
        ];

        foreach ($expectations as $label => [$change, $tabs]) {
            $this->resetQueue();
            $change();
            foreach (SheetTabs::keys() as $tab) {
                $this->assertSame(in_array($tab, $tabs, true) ? 1 : 0, $this->jobsFor($tab), "{$label} -> {$tab}");
            }
        }
    }

    public function test_deleting_and_restoring_a_client_triggers_sync(): void
    {
        $client = $this->person('Budi');
        $this->resetQueue();

        $client->delete();
        $this->assertSame(1, $this->jobsFor('client'));

        $this->resetQueue();
        $client->restore();
        $this->assertSame(1, $this->jobsFor('client'));
    }

    public function test_no_sync_is_scheduled_when_disabled_or_unconfigured(): void
    {
        $tutor = $this->teacher('Dewi');

        foreach ([['koedu.sheets.enabled', false], ['koedu.sheets.spreadsheet_id', ''], ['koedu.sheets.credentials_path', '/tidak/ada.json']] as [$key, $value]) {
            config(['koedu.sheets.enabled' => true, 'koedu.sheets.spreadsheet_id' => self::SHEET_ID, 'koedu.sheets.credentials_path' => $this->keyFile]);
            config([$key => $value]);
            $this->resetQueue();

            $tutor->update(['name' => "Dewi {$key}"]);

            Queue::assertNotPushed(SyncSheetTabJob::class);
        }
    }

    public function test_direct_query_updates_do_not_trigger_sync(): void
    {
        $session = $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00');
        $this->resetQueue();

        DB::table('consultations')->where('id', $session->id)->update(['title' => 'lewat query']);
        Consultation::query()->whereKey($session->id)->update(['topic' => 'lewat builder']);

        Queue::assertNotPushed(SyncSheetTabJob::class);
    }

    public function test_sent_notifications_refresh_the_schedule_tab(): void
    {
        $this->at_('10:00');
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$this->person('Budi')]);
        $this->resetQueue();

        $this->run_();

        $this->assertSame(1, $this->jobsFor('jadwal'), 'kolom "diberi tahu" berubah lewat query langsung, jadi dipicu manual');

        $this->resetQueue();
        $this->run_(); // tidak ada yang terkirim lagi
        $this->assertSame(0, $this->jobsFor('jadwal'));
    }

    public function test_touch_deduplicates_tabs_and_all_dispatches_every_tab_immediately(): void
    {
        $dispatcher = app(\App\Services\Sheets\SheetSyncDispatcher::class);
        $this->resetQueue();

        $this->assertSame(['jadwal', 'client'], $dispatcher->touch('jadwal', 'client', 'jadwal'));
        $this->assertSame(2, Queue::pushed(SyncSheetTabJob::class)->count());

        $this->resetQueue();
        $this->assertSame(['jadwal', 'client', 'paket'], $dispatcher->all());
        Queue::assertPushed(SyncSheetTabJob::class, fn (SyncSheetTabJob $job) => $job->delay === null);
        $this->assertSame(3, Queue::pushed(SyncSheetTabJob::class)->count());

        config(['koedu.sheets.enabled' => false]);
        $this->assertSame([], $dispatcher->all());
        $this->assertSame([], $dispatcher->touch('jadwal'));
    }

    public function test_models_unrelated_to_sheets_do_not_trigger_anything(): void
    {
        $this->resetQueue();

        \App\Models\WaConversation::create(['phone' => '6281']);
        \App\Models\User::factory()->create();

        Queue::assertNotPushed(SyncSheetTabJob::class);
        $this->assertInstanceOf(Client::class, Client::factory()->make());
        $this->assertInstanceOf(Tutor::class, Tutor::factory()->make());
    }
}
