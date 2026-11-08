<?php

namespace Tests\Feature\Notifications;

use App\Actions\Consultations\CancelConsultation;
use App\Actions\Consultations\ScheduleConsultation;
use App\Models\User;
use App\Services\Notifications\NotificationRunResult;

class SessionNoticesTest extends NotificationTestCase
{
    public function test_new_session_notifies_tutor_and_client_exactly_once(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $client = $this->person('Budi Santoso');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$client], 90);

        $result = $this->run_();

        $this->assertSame(2, $result->total(NotificationRunResult::SENT));
        $this->assertQueuedJobs(2);

        [$tutorMsg] = $this->bodiesTo($tutor->phone);
        $this->assertStringContainsString('Halo Dewi Tutor, ada jadwal bimbingan baru untuk Anda.', $tutorMsg);
        $this->assertStringContainsString('Bimbingan Bab 2', $tutorMsg);
        $this->assertStringContainsString('Selasa, 10 November 2026 pukul 14.00-15.30 WITA', $tutorMsg);
        $this->assertStringContainsString('Client: Budi Santoso', $tutorMsg);
        $this->assertStringContainsString('Link: https://zoom.us/j/123', $tutorMsg);

        [$clientMsg] = $this->bodiesTo($client->phone);
        $this->assertStringContainsString('Halo Budi Santoso, jadwal bimbingan Anda sudah dikonfirmasi.', $clientMsg);
        $this->assertStringContainsString('Tutor: Dewi Tutor', $clientMsg);

        $session->refresh();
        $this->assertNotNull($session->tutor_notified_at);
        $this->assertSame($tutor->id, $session->tutor_notified_tutor_id);
        $this->assertTrue($session->tutor_notified_starts_at->equalTo($session->starts_at));
        $participant = $session->participants()->first();
        $this->assertNotNull($participant->confirmation_sent_at);
        $this->assertSame($tutor->id, $participant->confirmed_tutor_id);

        // Putaran berikutnya tidak mengirim apa pun lagi.
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT));
        $this->assertCount(2, $this->outbound());
    }

    public function test_changed_time_tells_both_the_old_and_new_time(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $client = $this->person('Budi Santoso');
        $admin = User::factory()->admin()->create();
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$client]);
        $this->run_();

        $moved = $this->at('16:00', self::TUESDAY);
        app(ScheduleConsultation::class)->handle($this->input($tutor, [$client], overrides: ['startsAt' => $moved, 'endsAt' => $moved->addHour()]), $admin, true, $session);
        $result = $this->run_();

        $this->assertSame(2, $result->total(NotificationRunResult::SENT));
        $tutorMsg = $this->bodiesTo($tutor->phone)[1];
        $this->assertStringContainsString('jadwal bimbingan Anda diperbarui', $tutorMsg);
        $this->assertStringContainsString('Sebelumnya: Selasa, 10 November 2026 pukul 14.00', $tutorMsg);
        $this->assertStringContainsString('Sekarang: Selasa, 10 November 2026 pukul 16.00-17.00', $tutorMsg);
        $this->assertStringContainsString('Sebelumnya: Selasa, 10 November 2026 pukul 14.00', $this->bodiesTo($client->phone)[1]);
    }

    public function test_moving_a_session_back_to_the_known_time_sends_nothing(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $client = $this->person('Budi Santoso');
        $admin = User::factory()->admin()->create();
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$client]);
        $this->run_();
        $before = $this->outbound()->count();

        foreach (['16:00', '14:00'] as $time) {
            $start = $this->at($time, self::TUESDAY);
            app(ScheduleConsultation::class)->handle($this->input($tutor, [$client], overrides: ['startsAt' => $start, 'endsAt' => $start->addHour()]), $admin, true, $session);
        }
        $result = $this->run_();

        $this->assertCount($before, $this->outbound(), 'waktu kembali ke yang sudah diketahui, tidak ada yang perlu diumumkan');
        $this->assertSame(2, $result->total(NotificationRunResult::CLAIMED_ONLY));
        $this->assertNotNull($session->refresh()->tutor_notified_at, 'penanda tetap diperbarui supaya tidak dihitung ulang');
    }

    public function test_reassigning_the_tutor_relieves_the_old_one_and_informs_the_client(): void
    {
        $this->at_('10:00');
        $oldTutor = $this->teacher('Tutor Lama');
        $newTutor = $this->teacher('Tutor Baru');
        $client = $this->person('Budi Santoso');
        $admin = User::factory()->admin()->create();
        $session = $this->sessionAt($oldTutor, self::TUESDAY, '14:00', [$client]);
        $this->run_();

        app(ScheduleConsultation::class)->handle($this->input($newTutor, [$client], overrides: ['startsAt' => $this->at('14:00', self::TUESDAY), 'endsAt' => $this->at('15:00', self::TUESDAY)]), $admin, true, $session);
        $result = $this->run_();

        $this->assertSame(3, $result->total(NotificationRunResult::SENT));
        $this->assertStringContainsString('dialihkan ke tutor lain', $this->bodiesTo($oldTutor->phone)[1]);
        $this->assertStringContainsString('ada jadwal bimbingan baru untuk Anda', $this->bodiesTo($newTutor->phone)[0], 'tutor baru menerima "jadwal baru", bukan "diubah"');
        $clientMsg = $this->bodiesTo($client->phone)[1];
        $this->assertStringContainsString('tutor untuk jadwal bimbingan Anda diganti dari Tutor Lama', $clientMsg);
        $this->assertStringContainsString('Tutor: Tutor Baru', $clientMsg);
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT));
    }

    public function test_cancellation_reaches_only_those_already_told_and_hides_the_reason(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $told = $this->person('Budi Santoso');
        $admin = User::factory()->admin()->create();
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$told]);
        $this->run_();

        // Peserta baru ditambahkan lalu sesi dibatalkan sebelum sempat dikonfirmasi.
        $late = $this->person('Siti Aminah');
        $session->participants()->create(['client_id' => $late->id]);
        app(CancelConsultation::class)->handle($session, 'Rahasia internal: tutor sakit', $admin);
        $result = $this->run_();

        $this->assertSame(2, $result->total(NotificationRunResult::SENT), 'tutor dan client yang sudah diberi tahu');
        $this->assertStringContainsString('dibatalkan dan tidak perlu Anda hadiri', $this->bodiesTo($tutor->phone)[1]);
        $this->assertStringContainsString('mohon maaf, jadwal bimbingan berikut dibatalkan', $this->bodiesTo($told->phone)[1]);
        $this->assertSame([], $this->bodiesTo($late->phone), 'client yang tidak pernah diberi tahu tidak perlu menerima pembatalan');
        foreach ($this->outbound() as $message) {
            $this->assertStringNotContainsString('Rahasia internal', $message->body);
        }
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT));
    }

    public function test_session_cancelled_before_any_notice_sends_nothing(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$this->person('Budi')]);

        app(CancelConsultation::class)->handle($session, 'Salah input', User::factory()->admin()->create());
        $result = $this->run_();

        $this->assertNothingSent();
        $this->assertSame([], $result->counts());
    }

    public function test_client_added_to_a_group_later_gets_a_confirmation(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $first = $this->person('Budi');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$first], attributes: ['title' => 'Kelas Metodologi']);
        $this->run_();

        $second = $this->person('Siti');
        $session->participants()->create(['client_id' => $second->id]);
        $result = $this->run_();

        $this->assertSame(1, $result->total(NotificationRunResult::SENT));
        $this->assertStringContainsString('Halo Siti, jadwal bimbingan Anda sudah dikonfirmasi', $this->bodiesTo($second->phone)[0]);
        $this->assertCount(1, $this->bodiesTo($first->phone), 'peserta lama tidak diberi tahu ulang');
    }

    public function test_sessions_in_the_past_are_ignored(): void
    {
        $this->at_('10:00');
        $this->sessionAt($this->teacher('Dewi'), self::MONDAY, '08:00', [$this->person('Budi')]);

        $this->run_();

        $this->assertNothingSent();
    }

    public function test_deleted_clients_are_skipped(): void
    {
        $this->at_('10:00');
        $client = $this->person('Budi');
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$client]);
        $client->delete();

        $result = $this->run_();

        $this->assertSame(1, $result->total(NotificationRunResult::SENT), 'hanya tutor');
    }
}
