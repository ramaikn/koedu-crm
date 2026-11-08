<?php

namespace Tests\Feature\Notifications;

use App\Actions\Consultations\CancelConsultation;
use App\Models\User;
use App\Services\Notifications\NotificationRunResult;

class ReminderTest extends NotificationTestCase
{
    /** Sesi Selasa 10:00, dibuat dan diberi tahu Senin 10:00. */
    private function notifiedSession(?array $clients = null)
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $clients ??= [$this->person('Budi Santoso')];
        $session = $this->sessionAt($tutor, self::TUESDAY, '10:00', $clients);
        $this->run_();

        return [$session, $tutor, $clients];
    }

    private function sentCount(string $type): int
    {
        return $this->outbound()->filter(fn ($m) => str_contains((string) $m->body, match ($type) {
            'day' => 'pengingat: besok',
            'hour_client' => 'bimbingan Anda dimulai',
            'hour_tutor' => 'Pengingat: bimbingan',
        }))->count();
    }

    public function test_client_gets_a_day_before_reminder_at_the_configured_time_only(): void
    {
        [, , [$client]] = $this->notifiedSession();
        $baseline = $this->outbound()->count();

        $this->at_('17:59');
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT), 'belum jam 18.00');

        $this->at_('18:00');
        $result = $this->run_();

        $this->assertSame(1, $result->total(NotificationRunResult::SENT));
        $this->assertSame($baseline + 1, $this->outbound()->count());
        $reminder = $this->bodiesTo($client->phone)[1];
        $this->assertStringContainsString('pengingat: besok Anda ada jadwal bimbingan', $reminder);
        $this->assertStringContainsString('Selasa, 10 November 2026 pukul 10.00-11.00 WITA', $reminder);

        $this->at_('19:00');
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT), 'sekali saja');
    }

    public function test_hour_before_reminders_go_to_client_and_tutor(): void
    {
        [, $tutor, [$client]] = $this->notifiedSession();

        $this->at_('08:59', self::TUESDAY);
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT));

        $this->at_('09:00', self::TUESDAY);
        $result = $this->run_();

        // Pengingat H-1 tidak ikut: sesinya hari ini, bukan besok.
        $this->assertSame(2, $result->total(NotificationRunResult::SENT), 'tutor dan client');
        $this->assertStringContainsString('Pengingat: bimbingan "Bimbingan Bab 2" dimulai 60 menit lagi', collect($this->bodiesTo($tutor->phone))->last());
        $this->assertStringContainsString('bimbingan Anda dimulai 60 menit lagi', collect($this->bodiesTo($client->phone))->last());

        $this->at_('09:05', self::TUESDAY);
        $this->assertSame(0, $this->run_()->total(NotificationRunResult::SENT));
    }

    public function test_day_reminder_is_skipped_when_confirmation_came_after_the_reminder_time(): void
    {
        $this->at_('19:30'); // setelah 18.00 hari sebelumnya
        $tutor = $this->teacher('Dewi Tutor');
        $client = $this->person('Budi');
        $this->sessionAt($tutor, self::TUESDAY, '10:00', [$client]);
        $this->run_();
        $sent = $this->outbound()->count();

        $this->at_('20:00');
        $this->run_();

        $this->assertSame($sent, $this->outbound()->count(), 'konfirmasi baru saja dikirim, pengingat besok tidak perlu menyusul');
    }

    public function test_same_day_sessions_get_no_tomorrow_reminder(): void
    {
        $this->at_('07:00');
        $client = $this->person('Budi');
        $this->sessionAt($this->teacher('Dewi'), self::MONDAY, '15:00', [$client]);
        $this->run_();

        $this->at_('18:00');
        $this->run_();
        $this->at_('13:59'); // sebelum pengingat 1 jam
        $this->run_();

        $this->assertSame(0, $this->sentCount('day'));
        $this->at_('14:00');
        $this->run_();
        $this->assertSame(1, $this->sentCount('hour_client'), 'pengingat 1 jam tetap ada untuk sesi hari ini');
    }

    public function test_no_hour_reminder_when_informed_less_than_an_hour_before(): void
    {
        $this->at_('09:30', self::TUESDAY);
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '10:00', [$this->person('Budi')]);
        $this->run_(); // kabar jadwal dikirim 30 menit sebelum sesi
        $sent = $this->outbound()->count();

        $this->at_('09:40', self::TUESDAY);
        $this->run_();

        $this->assertSame($sent, $this->outbound()->count());
    }

    public function test_a_reminder_that_is_too_close_to_the_session_is_dropped(): void
    {
        $this->notifiedSession();
        $this->at_('18:00');
        $this->run_(); // H-1 terkirim
        $sent = $this->outbound()->count();

        $this->at_('09:55', self::TUESDAY); // jadwal pemicu terlambat: sisa 5 menit < batas 10 menit
        $this->run_();

        $this->assertSame($sent, $this->outbound()->count());
    }

    public function test_group_session_reminds_the_tutor_once_and_every_client(): void
    {
        $clients = [$this->person('A'), $this->person('B'), $this->person('C')];
        [, $tutor] = $this->notifiedSession($clients);

        $this->at_('09:00', self::TUESDAY);
        $this->run_();

        $this->assertSame(1, $this->sentCount('hour_tutor'));
        $this->assertSame(3, $this->sentCount('hour_client'));
    }

    public function test_cancelled_sessions_get_no_reminders(): void
    {
        [$session] = $this->notifiedSession();
        app(CancelConsultation::class)->handle($session, 'Batal', User::factory()->admin()->create());
        $this->at_('18:00');
        $this->run_(); // pembatalan terkirim
        $sent = $this->outbound()->count();

        $this->at_('09:00', self::TUESDAY);
        $this->run_();

        $this->assertSame($sent, $this->outbound()->count());
    }

    public function test_changed_time_recomputes_reminders(): void
    {
        [$session, $tutor, [$client]] = $this->notifiedSession();
        $this->at_('18:00');
        $this->run_(); // H-1 untuk Selasa 10.00 terkirim

        // Dipindah ke Rabu 10.00: pengingat dihitung ulang dari awal.
        $moved = $this->at('10:00', '2026-11-11');
        app(\App\Actions\Consultations\ScheduleConsultation::class)->handle(
            $this->input($tutor, [$client], overrides: ['startsAt' => $moved, 'endsAt' => $moved->addHour()]),
            User::factory()->admin()->create(), true, $session,
        );
        $this->at_('18:30');
        $this->run_(); // kabar perubahan
        $sent = $this->sentCount('day');

        $this->at_('18:00', self::TUESDAY);
        $this->run_();

        $this->assertSame($sent + 1, $this->sentCount('day'), 'pengingat H-1 untuk jadwal baru terkirim');
    }
}
