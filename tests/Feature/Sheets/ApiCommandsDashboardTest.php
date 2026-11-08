<?php

namespace Tests\Feature\Sheets;

use App\Jobs\SyncSheetTabJob;
use App\Services\Sheets\SheetSyncStatus;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

class ApiCommandsDashboardTest extends SheetsTestCase
{
    // --- API n8n ---

    public function test_api_requires_the_token(): void
    {
        config(['koedu.n8n.api_token' => 'rahasia']);

        $this->postJson('/api/n8n/sheets/sync')->assertUnauthorized();
        $this->withHeader('X-Koedu-Token', 'salah')->postJson('/api/n8n/sheets/sync')->assertUnauthorized();
    }

    public function test_api_queues_a_full_reconciliation_of_every_tab(): void
    {
        config(['koedu.n8n.api_token' => 'rahasia']);
        Queue::fake();

        $this->withHeader('X-Koedu-Token', 'rahasia')->postJson('/api/n8n/sheets/sync')
            ->assertOk()->assertJson(['enabled' => true, 'queued' => ['jadwal', 'client', 'paket']]);

        Queue::assertPushed(SyncSheetTabJob::class, 3);
        Queue::assertPushedOn('sheets', SyncSheetTabJob::class);
    }

    public function test_api_does_nothing_when_disabled(): void
    {
        config(['koedu.n8n.api_token' => 'rahasia', 'koedu.sheets.enabled' => false]);
        Queue::fake();

        $this->withHeader('X-Koedu-Token', 'rahasia')->postJson('/api/n8n/sheets/sync')->assertOk()->assertJson(['enabled' => false, 'queued' => []]);

        Queue::assertNotPushed(SyncSheetTabJob::class);
    }

    // --- sheets:check ---

    public function test_check_explains_missing_setup_without_calling_google(): void
    {
        config(['koedu.sheets.enabled' => false, 'koedu.sheets.spreadsheet_id' => '', 'koedu.sheets.credentials_path' => '/tidak/ada.json']);
        \Illuminate\Support\Facades\Http::fake();

        $this->artisan('sheets:check')
            ->expectsOutputToContain('KOSONG')
            ->expectsOutputToContain('TIDAK ADA')
            ->expectsOutputToContain('Penyiapan belum lengkap')
            ->assertFailed();

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_check_reports_account_spreadsheet_and_tabs_on_success(): void
    {
        $this->googleTabs = ['Jadwal' => 11];
        $this->fakeGoogle();

        $this->artisan('sheets:check')
            ->expectsOutputToContain(self::EMAIL)
            ->expectsOutputToContain('Koedu Uji')
            ->expectsOutputToContain('sudah ada')
            ->expectsOutputToContain('akan dibuat otomatis')
            ->expectsOutputToContain('Akses berhasil')
            ->assertSuccessful();
    }

    public function test_check_tells_the_user_to_share_the_sheet_when_access_is_denied(): void
    {
        $this->googleFailure = 403;
        $this->fakeGoogle();

        $this->artisan('sheets:check')->expectsOutputToContain('Bagikan Sheet ke '.self::EMAIL)->assertFailed();
    }

    public function test_check_reports_temporary_problems_as_retryable(): void
    {
        $this->googleFailure = 503;
        $this->fakeGoogle();

        $this->artisan('sheets:check')->expectsOutputToContain('Coba lagi beberapa saat lagi')->assertFailed();
    }

    // --- sheets:sync ---

    public function test_sync_command_runs_immediately_with_now(): void
    {
        $this->at_('10:00');
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$this->person('Budi')]);
        $this->fakeGoogle();

        $this->artisan('sheets:sync', ['tab' => 'jadwal', '--now' => true])->expectsOutputToContain('jadwal: 1 baris disalin.')->assertSuccessful();
        $this->artisan('sheets:sync', ['--now' => true])->expectsOutputToContain('client:')->expectsOutputToContain('paket:')->assertSuccessful();
    }

    public function test_sync_command_without_now_uses_the_queue_and_validates_input(): void
    {
        Queue::fake();

        $this->artisan('sheets:sync', ['tab' => 'client'])->expectsOutputToContain('Dijadwalkan lewat antrean')->assertSuccessful();
        Queue::assertPushed(SyncSheetTabJob::class, fn ($job) => $job->tab === 'client');

        $this->artisan('sheets:sync', ['tab' => 'rahasia'])->expectsOutputToContain('Tab tidak dikenal')->assertFailed();
    }

    public function test_sync_command_reports_failures_and_disabled_state(): void
    {
        $this->googleFailure = 403;
        $this->fakeGoogle();
        $this->artisan('sheets:sync', ['--now' => true])->expectsOutputToContain('Akses ditolak')->assertFailed();

        config(['koedu.sheets.enabled' => false]);
        $this->artisan('sheets:sync')->expectsOutputToContain('belum aktif')->assertFailed();
    }

    // --- Dashboard ---

    public function test_dashboard_shows_sheet_link_and_sync_status_to_staff_only_when_enabled(): void
    {
        app(SheetSyncStatus::class)->succeeded('jadwal', 42);
        app(SheetSyncStatus::class)->failed('client', 'config');

        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('sheets.url', 'https://docs.google.com/spreadsheets/d/'.self::SHEET_ID)
            ->where('sheets.tabs.0.tab', 'jadwal')
            ->where('sheets.tabs.0.rows', 42)
            ->where('sheets.tabs.0.error', null)
            ->where('sheets.tabs.1.error', 'config')
            ->where('sheets.tabs.2.synced_at', null));

        $this->actingAs($this->anyDayTutor()->user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->missing('sheets'));

        config(['koedu.sheets.enabled' => false]);
        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('sheets', null));
    }
}
