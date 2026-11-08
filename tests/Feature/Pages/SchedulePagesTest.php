<?php

namespace Tests\Feature\Pages;

use App\Enums\AttendanceStatus;
use App\Enums\BenefitType;
use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

class SchedulePagesTest extends PageTestCase
{
    /** Sesi yang sudah lewat (kemarin) untuk menguji penyelesaian. */
    private function pastSession($tutor, array $clients)
    {
        $start = CarbonImmutable::now('Asia/Makassar')->subDay()->setTime(10, 0);

        return $this->makeSession($tutor, attributes: ['starts_at' => $start, 'ends_at' => $start->addHour()], clients: $clients);
    }

    private function futureSession($tutor, array $clients)
    {
        $start = CarbonImmutable::now('Asia/Makassar')->addDays(2)->setTime(10, 0);

        return $this->makeSession($tutor, attributes: ['starts_at' => $start, 'ends_at' => $start->addHour()], clients: $clients);
    }

    public function test_admin_creates_session(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();

        $response = $this->actingAs($this->admin())->post('/schedule', $this->scheduleForm($tutor, $client));

        $consultation = Consultation::firstOrFail();
        $response->assertRedirect("/schedule/{$consultation->id}");
        $this->assertSame($tutor->id, $consultation->tutor_id);
        $this->assertSame(1, $consultation->participants()->count());
    }

    public function test_only_admin_creates_edits_or_cancels(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $session = $this->futureSession($tutor, [$client]);

        foreach ([$this->director(), $tutor->user] as $user) {
            $this->actingAs($user)->get('/schedule/create')->assertForbidden();
            $this->actingAs($user)->post('/schedule', $this->scheduleForm($tutor, $client))->assertForbidden();
            $this->actingAs($user)->put("/schedule/{$session->id}", $this->scheduleForm($tutor, $client))->assertForbidden();
            $this->actingAs($user)->post("/schedule/{$session->id}/cancel", ['reason' => 'x'])->assertForbidden();
        }
        $this->assertSame(1, Consultation::count());
    }

    public function test_conflict_is_reported_on_the_schedule_key_and_nothing_saved(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $client));

        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $this->activeClient(), ['start_time' => '10:30']))
            ->assertSessionHasErrors('schedule');

        $this->assertSame(1, Consultation::count());
    }

    public function test_warnings_must_be_confirmed(): void
    {
        $tutor = $this->anyDayTutor();
        $unpaid = $this->activeClient();
        $unpaid->enrollments()->update(['payment_status' => 'unpaid']);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $unpaid))->assertSessionHasErrors('confirm_warnings');
        $this->assertSame(0, Consultation::count());

        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $unpaid, ['confirm_warnings' => true]))->assertSessionHasNoErrors();
        $this->assertSame(1, Consultation::count());
    }

    public function test_new_session_must_be_in_the_future(): void
    {
        $tutor = $this->anyDayTutor();

        $this->actingAs($this->admin())->post('/schedule', $this->scheduleForm($tutor, $this->activeClient(), ['date' => $this->daysFromNow(-1)]))
            ->assertSessionHasErrors('date');
    }

    public function test_group_class_accepts_many_clients_up_to_capacity(): void
    {
        $tutor = $this->anyDayTutor();
        $clients = [$this->activeClient(), $this->activeClient(), $this->activeClient()];
        $admin = $this->admin();
        $group = ['type' => 'group', 'benefit_type' => 'group_meeting', 'client_ids' => array_map(fn ($c) => $c->id, $clients), 'confirm_warnings' => true];

        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $clients[0], $group + ['capacity' => 2]))->assertSessionHasErrors('schedule');
        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $clients[0], $group + ['capacity' => 20]))->assertSessionHasNoErrors();

        $this->assertSame(3, Consultation::first()->participants()->count());
    }

    public function test_index_scopes_tutors_to_their_own_sessions_even_with_a_tutor_filter(): void
    {
        $mine = $this->anyDayTutor();
        $other = $this->anyDayTutor();
        $client = $this->activeClient();
        $this->futureSession($mine, [$client]);
        $this->makeSession($other, '14:00', attributes: [
            'starts_at' => CarbonImmutable::now('Asia/Makassar')->addDays(2)->setTime(14, 0), 'ends_at' => CarbonImmutable::now('Asia/Makassar')->addDays(2)->setTime(15, 0),
        ]);
        $query = http_build_query(['from' => $this->daysFromNow(0), 'to' => $this->daysFromNow(5), 'tutor_id' => $other->id]);

        $this->actingAs($mine->user)->get("/schedule?{$query}")
            ->assertInertia(fn (Assert $p) => $p->component('schedule/index')->has('sessions', 1)->where('sessions.0.tutor.id', $mine->id)->where('is_tutor', true));

        $this->actingAs($this->admin())->get("/schedule?{$query}")->assertInertia(fn (Assert $p) => $p->has('sessions', 1)->where('sessions.0.tutor.id', $other->id));
    }

    public function test_index_filters_by_range_status_and_client_name(): void
    {
        $tutor = $this->anyDayTutor();
        $siti = $this->activeClient();
        $siti->update(['name' => 'Siti Aminah']);
        $future = $this->futureSession($tutor, [$siti]);
        $range = http_build_query(['from' => $this->daysFromNow(0), 'to' => $this->daysFromNow(5)]);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/schedule?{$range}&q=siti")->assertInertia(fn (Assert $p) => $p->has('sessions', 1));
        $this->actingAs($admin)->get("/schedule?{$range}&q=zzz")->assertInertia(fn (Assert $p) => $p->has('sessions', 0));
        $this->actingAs($admin)->get("/schedule?{$range}&status=cancelled")->assertInertia(fn (Assert $p) => $p->has('sessions', 0));
        $this->actingAs($admin)->get('/schedule?from='.$this->daysFromNow(10).'&to='.$this->daysFromNow(12))->assertInertia(fn (Assert $p) => $p->has('sessions', 0));
        $this->assertNotNull($future);
    }

    public function test_tutor_can_open_only_own_session(): void
    {
        $mine = $this->anyDayTutor();
        $other = $this->anyDayTutor();
        $client = $this->activeClient();
        $own = $this->futureSession($mine, [$client]);
        $foreign = $this->futureSession($other, [$this->activeClient()]);

        $this->actingAs($mine->user)->get("/schedule/{$own->id}")->assertOk();
        $this->actingAs($mine->user)->get("/schedule/{$foreign->id}")->assertForbidden();
        $this->actingAs($mine->user)->post("/schedule/{$foreign->id}/complete")->assertForbidden();
    }

    public function test_session_cannot_be_completed_before_it_starts(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $session = $this->futureSession($tutor, [$client]);

        $this->actingAs($tutor->user)->get("/schedule/{$session->id}")
            ->assertInertia(fn (Assert $p) => $p->where('can.complete', false)->where('can.complete_blocked_until_start', true));
        $this->actingAs($tutor->user)->post("/schedule/{$session->id}/complete")->assertSessionHasErrors('status');

        $this->assertSame(ConsultationStatus::Scheduled, $session->refresh()->status);
        $this->assertSame(0, $client->enrollments()->first()->quotaFor(BenefitType::ZoomSession)->used);
    }

    public function test_tutor_completes_past_session_with_attendance_and_notes(): void
    {
        $tutor = $this->anyDayTutor();
        $present = $this->activeClient();
        $absent = $this->activeClient();
        $session = $this->pastSession($tutor, [$present, $absent]);
        $absentParticipant = $session->participants()->where('client_id', $absent->id)->first();

        $this->actingAs($tutor->user)->post("/schedule/{$session->id}/complete", [
            'attendance' => [$absentParticipant->id => 'absent', 9999 => 'absent'],
            'tutor_notes' => 'Bab 1 selesai direvisi.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ConsultationStatus::Completed, $session->refresh()->status);
        $this->assertSame('Bab 1 selesai direvisi.', $session->tutor_notes);
        $this->assertSame(1, $present->enrollments()->first()->quotaFor(BenefitType::ZoomSession)->used);
        $this->assertSame(0, $absent->enrollments()->first()->quotaFor(BenefitType::ZoomSession)->used, 'tidak hadir tidak dipotong');
        $this->assertSame(AttendanceStatus::Absent, $absentParticipant->refresh()->attendance);
    }

    public function test_cancel_requires_reason_and_frees_the_slot(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $session = $this->futureSession($tutor, [$client]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/schedule/{$session->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/schedule/{$session->id}/cancel", ['reason' => 'Client sakit'])->assertSessionHasNoErrors();

        $this->assertSame(ConsultationStatus::Cancelled, $session->refresh()->status);
        $this->actingAs($admin)->get("/schedule/{$session->id}/edit")->assertForbidden();
    }

    public function test_edit_and_update_session(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/schedule', $this->scheduleForm($tutor, $client));
        $session = Consultation::firstOrFail();

        $this->actingAs($admin)->get("/schedule/{$session->id}/edit")
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('schedule/form')->where('consultation.start_time', '10:00')->where('consultation.duration_minutes', 60)->has('preset_clients', 1));

        $this->actingAs($admin)->put("/schedule/{$session->id}", $this->scheduleForm($tutor, $client, ['start_time' => '14:00', 'title' => 'Dipindah']))
            ->assertRedirect("/schedule/{$session->id}");

        $this->assertSame('Dipindah', $session->refresh()->title);
        $this->assertSame('14:00', $session->starts_at->setTimezone('Asia/Makassar')->format('H:i'));
    }

    public function test_check_endpoint(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/schedule/check', ['type' => 'private'])->assertOk()->assertJson(['ready' => false, 'errors' => [], 'warnings' => []]);
        $this->actingAs($admin)->postJson('/schedule/check', $this->scheduleForm($tutor, $client))->assertOk()->assertJson(['ready' => true, 'errors' => [], 'warnings' => []]);

        $this->makeSession($tutor, attributes: [
            'starts_at' => CarbonImmutable::parse($this->daysFromNow(1).' 10:00', 'Asia/Makassar'), 'ends_at' => CarbonImmutable::parse($this->daysFromNow(1).' 11:00', 'Asia/Makassar'),
        ]);
        $this->actingAs($admin)->postJson('/schedule/check', $this->scheduleForm($tutor, $client))->assertJsonPath('ready', true)->assertJsonCount(1, 'errors');

        $this->actingAs($tutor->user)->postJson('/schedule/check', $this->scheduleForm($tutor, $client))->assertForbidden();
    }

    public function test_suggest_endpoint_ranks_tutors(): void
    {
        $field = \App\Models\ExpertiseField::create(['name' => 'Akuntansi', 'group' => 'Ekonomi']);
        $match = $this->anyDayTutor(['name' => 'Cocok']);
        $match->expertiseFields()->attach($field);
        $this->anyDayTutor(['name' => 'Lain']);
        $client = $this->activeClient();
        $client->update(['expertise_field_id' => $field->id]);

        $response = $this->actingAs($this->admin())->postJson('/schedule/suggest-tutors', [
            'date' => $this->daysFromNow(1), 'start_time' => '10:00', 'duration_minutes' => 60, 'client_id' => $client->id,
        ])->assertOk();

        $response->assertJsonPath('eligible.0.name', 'Cocok')->assertJsonPath('eligible.0.free', true);
        $this->assertContains('Bidang sama persis (Akuntansi)', $response->json('eligible.0.reasons'));

        $this->actingAs($match->user)->postJson('/schedule/suggest-tutors', ['date' => $this->daysFromNow(1), 'start_time' => '10:00', 'duration_minutes' => 60])->assertForbidden();
    }

    public function test_dashboard_shows_staff_stats_and_tutor_only_own_sessions(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient();
        $client->enrollments()->update(['payment_status' => 'unpaid']);
        $start = CarbonImmutable::now('Asia/Makassar')->addMinutes(30);
        $this->makeSession($tutor, attributes: ['starts_at' => $start, 'ends_at' => $start->addHour()], clients: [$client]);
        $this->makeSession($this->anyDayTutor(), attributes: ['starts_at' => $start, 'ends_at' => $start->addHour()]);

        $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('dashboard')->where('role', 'admin')->where('stats.unpaid_enrollments', 1)->has('today'));

        $this->actingAs($tutor->user)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->where('role', 'tutor')->missing('stats')->has('today', 1));
    }
}
