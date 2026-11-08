<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Consultations\CancelConsultation;
use App\Actions\Consultations\CompleteConsultation;
use App\Actions\Consultations\ScheduleConsultation;
use App\Enums\AttendanceStatus;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationType;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ScheduleConsultationTest extends SchedulingTestCase
{
    private function schedule(...$args): Consultation
    {
        return app(ScheduleConsultation::class)->handle(...$args);
    }

    public function test_creates_session_and_links_participant_to_the_usable_package(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();
        $admin = User::factory()->admin()->create();

        $consultation = $this->schedule($this->input($tutor, [$client]), $admin);

        $this->assertSame($tutor->id, $consultation->tutor_id);
        $this->assertSame($admin->id, $consultation->created_by);
        $this->assertSame(ConsultationStatus::Scheduled, $consultation->status);
        $this->assertSame('2026-11-09 10:00', $consultation->starts_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
        $this->assertSame($this->enrollmentOf($client)->id, $consultation->participants->first()->enrollment_id);
    }

    public function test_conflict_is_rejected_and_nothing_is_saved(): void
    {
        $tutor = $this->tutor();
        $this->makeSession($tutor, '10:00');

        try {
            $this->schedule($this->input($tutor, [$this->clientWithPackage()], '10:30'), User::factory()->admin()->create());
            $this->fail('Seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('schedule', $e->errors());
        }

        $this->assertSame(1, Consultation::count());
    }

    public function test_warnings_require_confirmation(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();
        $admin = User::factory()->admin()->create();
        $outsideHours = $this->input($tutor, [$client], '20:00');

        try {
            $this->schedule($outsideHours, $admin);
            $this->fail('Seharusnya meminta konfirmasi.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirm_warnings', $e->errors());
        }
        $this->assertSame(0, Consultation::count());

        $this->schedule($outsideHours, $admin, confirmWarnings: true);
        $this->assertSame(1, Consultation::count());
    }

    public function test_errors_cannot_be_overridden_by_confirming(): void
    {
        $tutor = $this->tutor();
        $this->makeSession($tutor, '10:00');

        $this->expectException(ValidationException::class);
        $this->schedule($this->input($tutor, [$this->clientWithPackage()]), User::factory()->admin()->create(), confirmWarnings: true);
    }

    public function test_changing_time_resets_notifications_but_title_change_does_not(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();
        $admin = User::factory()->admin()->create();
        $consultation = $this->schedule($this->input($tutor, [$client]), $admin);
        $consultation->update(['tutor_notified_at' => now(), 'tutor_reminded_at' => now(), 'sheet_synced_at' => now()]);
        $consultation->participants()->update(['confirmation_sent_at' => now(), 'reminder_day_sent_at' => now()]);

        $this->schedule($this->input($tutor, [$client], overrides: ['title' => 'Judul baru']), $admin, existing: $consultation);
        $consultation->refresh();
        $this->assertNotNull($consultation->tutor_notified_at, 'judul berubah: notifikasi tidak diulang');
        $this->assertNull($consultation->sheet_synced_at, 'Sheets tetap perlu disegarkan');

        $this->schedule($this->input($tutor, [$client], '11:00'), $admin, existing: $consultation);
        $consultation->refresh();
        $this->assertNull($consultation->tutor_notified_at);
        $this->assertNull($consultation->tutor_reminded_at);
        $this->assertNull($consultation->participants()->first()->confirmation_sent_at);
        $this->assertNull($consultation->participants()->first()->reminder_day_sent_at);
    }

    public function test_only_scheduled_sessions_can_be_edited(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();
        $consultation = $this->makeSession($tutor, '10:00', 60, [$client]);
        app(CompleteConsultation::class)->handle($consultation);

        $this->expectException(ValidationException::class);
        $this->schedule($this->input($tutor, [$client], '14:00'), User::factory()->admin()->create(), existing: $consultation);
    }

    public function test_group_participants_can_be_added_and_removed_keeping_attendance(): void
    {
        $tutor = $this->tutor();
        [$a, $b, $c] = [$this->clientWithPackage(), $this->clientWithPackage(), $this->clientWithPackage()];
        $admin = User::factory()->admin()->create();
        $group = ['type' => ConsultationType::Group, 'capacity' => 20];

        $consultation = $this->schedule($this->input($tutor, [$a, $b], overrides: $group), $admin);
        $consultation->participants()->where('client_id', $a->id)->update(['attendance' => AttendanceStatus::Present]);

        $this->schedule($this->input($tutor, [$a, $c], overrides: $group), $admin, existing: $consultation);

        $participants = $consultation->participants()->get()->keyBy('client_id');
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $participants->keys()->all());
        $this->assertSame(AttendanceStatus::Present, $participants[$a->id]->attendance);
    }

    public function test_cancel_marks_session_and_rejects_completed_ones(): void
    {
        $tutor = $this->tutor();
        $client = $this->clientWithPackage();
        $admin = User::factory()->admin()->create();
        $scheduled = $this->makeSession($tutor, '10:00', 60, [$client]);
        $done = $this->makeSession($tutor, '13:00', 60, [$client]);
        app(CompleteConsultation::class)->handle($done);

        $cancelled = app(CancelConsultation::class)->handle($scheduled, 'Client berhalangan', $admin);

        $this->assertSame(ConsultationStatus::Cancelled, $cancelled->status);
        $this->assertSame('Client berhalangan', $cancelled->cancel_reason);
        $this->assertSame(1, $this->enrollmentOf($client)->quotas->first()->used, 'hanya sesi selesai yang memotong kuota, bukan yang dibatalkan');

        $this->expectException(ValidationException::class);
        app(CancelConsultation::class)->handle($done, 'x', $admin);
    }
}
