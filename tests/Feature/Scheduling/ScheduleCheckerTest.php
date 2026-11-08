<?php

namespace Tests\Feature\Scheduling;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationType;
use App\Models\Client;
use App\Services\Scheduling\ScheduleChecker;

class ScheduleCheckerTest extends SchedulingTestCase
{
    private function check(...$args)
    {
        return app(ScheduleChecker::class)->check(...$args);
    }

    public function test_valid_schedule_has_no_errors_or_warnings(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();

        $result = $this->check($this->input($tutor, [$client]));

        $this->assertSame([], $result->errors);
        $this->assertSame([], $result->warnings);
    }

    public function test_tutor_conflict_is_an_error(): void
    {
        $tutor = $this->tutor();
        $this->makeSession($tutor, '10:00');

        $result = $this->check($this->input($tutor, [$this->clientWithPackage()], '10:30'));

        $this->assertFalse($result->passes());
        $this->assertStringContainsString("Tutor {$tutor->name} sudah punya sesi", $result->errors[0]);
    }

    public function test_back_to_back_sessions_do_not_conflict(): void
    {
        $tutor = $this->tutor();
        $this->makeSession($tutor, '10:00', 60);

        $this->assertTrue($this->check($this->input($tutor, [$this->clientWithPackage()], '11:00'))->passes());
        $this->assertTrue($this->check($this->input($tutor, [$this->clientWithPackage()], '09:00'))->passes());
    }

    public function test_cancelled_session_does_not_block_the_slot(): void
    {
        $tutor = $this->tutor();
        $this->makeSession($tutor, '10:00', 60, attributes: ['status' => ConsultationStatus::Cancelled]);

        $this->assertTrue($this->check($this->input($tutor, [$this->clientWithPackage()], '10:00'))->passes());
    }

    public function test_client_conflict_with_another_tutor_is_an_error(): void
    {
        $other = $this->tutor();
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();
        $this->makeSession($other, '10:00', 60, [$client]);

        $result = $this->check($this->input($tutor, [$client], '10:30'));

        $this->assertFalse($result->passes());
        $this->assertStringContainsString("Client {$client->name} sudah punya sesi", $result->errors[0]);
    }

    public function test_editing_a_session_ignores_its_own_slot(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();
        $session = $this->makeSession($tutor, '10:00', 60, [$client]);

        $this->assertFalse($this->check($this->input($tutor, [$client], '10:00'))->passes());
        $this->assertTrue($this->check($this->input($tutor, [$client], '10:00'), $session->id)->passes());
    }

    public function test_participant_rules(): void
    {
        $tutor = $this->tutor();
        $a = $this->clientWithPackage();
        $b = $this->clientWithPackage();

        $this->assertSame(['Pilih minimal satu client.'], $this->check($this->input($tutor, []))->errors);
        $this->assertSame(['Sesi privat hanya untuk satu client.'], $this->check($this->input($tutor, [$a, $b]))->errors);

        $group = $this->input($tutor, [$a, $b], overrides: ['type' => ConsultationType::Group, 'capacity' => 1]);
        $this->assertStringContainsString('melebihi kapasitas', $this->check($group)->errors[0]);

        $group = $this->input($tutor, [$a, $b], overrides: ['type' => ConsultationType::Group, 'capacity' => 20]);
        $this->assertTrue($this->check($group)->passes());
    }

    public function test_invalid_range_and_inactive_tutor(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();

        $this->assertSame(['Jam selesai harus setelah jam mulai.'], $this->check($this->input($tutor, [$client], minutes: 0))->errors);

        $tutor->update(['is_active' => false]);
        $this->assertStringContainsString('tidak ditemukan atau sudah tidak aktif', $this->check($this->input($tutor, [$client]))->errors[0]);
    }

    public function test_outside_availability_is_only_a_warning(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();

        $result = $this->check($this->input($tutor, [$client], '20:00'));

        $this->assertTrue($result->passes());
        $this->assertStringContainsString('di luar jam tersedia', $result->warnings[0]);
        $this->assertStringContainsString('09:00-12:00, 13:00-17:00', $result->warnings[0]);
    }

    public function test_session_spanning_two_slots_is_outside_availability(): void
    {
        $tutor = $this->tutor();

        // 11:30-13:30 melewati jeda istirahat tutor (12:00-13:00).
        $result = $this->check($this->input($tutor, [$this->clientWithPackage()], '11:30', 120));

        $this->assertCount(1, $result->warnings);
    }

    public function test_day_without_availability_warns(): void
    {
        $tutor = $this->tutor(withAvailability: false);

        $result = $this->check($this->input($tutor, [$this->clientWithPackage()]));

        $this->assertStringContainsString('tidak punya jam tersedia pada hari Senin', $result->warnings[0]);
    }

    public function test_weekly_limit_warns(): void
    {
        $tutor = $this->tutor(['max_weekly_sessions' => 2]);
        $this->makeSession($tutor, '09:00');

        $this->assertSame([], $this->check($this->input($tutor, [$this->clientWithPackage()], '10:00'))->warnings);

        $this->makeSession($tutor, '13:00');
        $warnings = $this->check($this->input($tutor, [$this->clientWithPackage()], '14:00'))->warnings;

        $this->assertStringContainsString('sudah punya 2 sesi minggu ini (batas 2)', $warnings[0]);
    }

    public function test_weekly_count_ignores_other_weeks_and_cancelled_sessions(): void
    {
        $tutor = $this->tutor(['max_weekly_sessions' => 1]);
        $this->makeSession($tutor, '09:00', attributes: ['status' => ConsultationStatus::Cancelled]);
        $nextWeek = $this->at('09:00', '2026-11-16');
        $this->makeSession($tutor, '09:00', attributes: ['starts_at' => $nextWeek, 'ends_at' => $nextWeek->addHour()]);

        $this->assertSame([], $this->check($this->input($tutor, [$this->clientWithPackage()], '10:00'))->warnings);
    }

    public function test_client_without_package_or_quota_warns(): void
    {
        $tutor = $this->tutor();
        $noPackage = Client::factory()->create();
        $noQuota = $this->clientWithPackage(zoomQuota: 1);
        $this->enrollmentOf($noQuota)->quotas()->update(['used' => 1]);

        $this->assertStringContainsString('tidak punya paket aktif', $this->check($this->input($tutor, [$noPackage]))->warnings[0]);
        $this->assertStringContainsString('tidak punya paket aktif', $this->check($this->input($tutor, [$noQuota]))->warnings[0]);
    }

    public function test_unpaid_package_warns(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage(paid: false);

        $result = $this->check($this->input($tutor, [$client]));

        $this->assertTrue($result->passes());
        $this->assertStringContainsString('belum lunas', $result->warnings[0]);
    }

    public function test_package_must_be_valid_on_the_session_date(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage(); // berlaku 1-30 Nov 2026

        $december = $this->at('10:00', '2026-12-07'); // Senin, setelah paket berakhir
        $tutor->availabilities()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00']);
        $input = $this->input($tutor, [$client], overrides: ['startsAt' => $december, 'endsAt' => $december->addHour()]);

        $this->assertStringContainsString('tidak punya paket aktif', $this->check($input)->warnings[0]);
    }
}
