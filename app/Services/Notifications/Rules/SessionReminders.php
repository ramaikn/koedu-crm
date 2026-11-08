<?php

namespace App\Services\Notifications\Rules;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Services\Notifications\MessageTemplates;
use App\Services\Notifications\NotificationRule;
use App\Services\Notifications\NotificationWindow;
use App\Services\Notifications\PlannedNotification;
use Carbon\CarbonImmutable;

/**
 * Pengingat sesi: client sehari sebelumnya (pukul 18.00) dan sebelum sesi (bawaan 60 menit), tutor sebelum sesi.
 *
 * Pengingat hanya dibuat bila masih berguna. Bila konfirmasi atau kabar jadwal baru dikirim setelah waktu
 * pengingat lewat, atau sesinya sudah terlalu dekat, pengingat itu dilewati (tidak dikirim, tidak dicatat).
 */
final class SessionReminders implements NotificationRule
{
    public function __construct(
        private readonly MessageTemplates $messages,
        private readonly NotificationWindow $window,
    ) {}

    public function plan(CarbonImmutable $now): array
    {
        return [...$this->clientDay($now), ...$this->clientHour($now), ...$this->tutorHour($now)];
    }

    /** @return list<PlannedNotification> */
    private function clientDay(CarbonImmutable $now): array
    {
        return $this->clientParticipants($now, 'reminder_day_sent_at', $now->addDays(2))
            ->filter(function (ConsultationParticipant $p) use ($now) {
                $start = CarbonImmutable::instance($p->consultation->starts_at);
                $at = $this->window->dayReminderAt($start);

                // Sesinya hari ini (atau lebih lampau) tidak diingatkan dengan "besok". Pengingat hanya untuk yang
                // dikonfirmasi sebelum waktu pengingat, supaya client tidak menerima dua pesan beruntun.
                return $now->greaterThanOrEqualTo($at)
                    && $start->setTimezone(config('app.timezone'))->startOfDay()->greaterThan($now->setTimezone(config('app.timezone'))->startOfDay())
                    && $p->confirmation_sent_at->lessThan($at);
            })
            ->map(fn (ConsultationParticipant $p) => new PlannedNotification(
                type: 'client_day_reminder',
                phone: $p->client->phone,
                recipient: $p->client->name,
                body: $this->messages->clientDayReminder($p->consultation, $p->client->name),
                claim: fn () => $this->claimParticipant($p, 'reminder_day_sent_at'),
                sessionStart: CarbonImmutable::instance($p->consultation->starts_at),
            ))
            ->values()
            ->all();
    }

    /** @return list<PlannedNotification> */
    private function clientHour(CarbonImmutable $now): array
    {
        return $this->clientParticipants($now, 'reminder_hour_sent_at', $now->addHours(3))
            ->filter(fn (ConsultationParticipant $p) => $this->hourReminderDue($now, CarbonImmutable::instance($p->consultation->starts_at), $p->confirmation_sent_at))
            ->map(fn (ConsultationParticipant $p) => new PlannedNotification(
                type: 'client_hour_reminder',
                phone: $p->client->phone,
                recipient: $p->client->name,
                body: $this->messages->clientHourReminder($p->consultation, $p->client->name, $this->minutesLeft($now, $p->consultation)),
                claim: fn () => $this->claimParticipant($p, 'reminder_hour_sent_at'),
                sessionStart: CarbonImmutable::instance($p->consultation->starts_at),
            ))
            ->values()
            ->all();
    }

    /** @return list<PlannedNotification> */
    private function tutorHour(CarbonImmutable $now): array
    {
        return Consultation::query()
            ->with(['tutor', 'participants.client'])
            ->where('status', ConsultationStatus::Scheduled)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours(3))
            ->whereNull('tutor_reminded_at')
            ->whereNotNull('tutor_notified_at')
            ->get()
            ->filter(fn (Consultation $c) => $this->hourReminderDue($now, CarbonImmutable::instance($c->starts_at), $c->tutor_notified_at))
            ->map(fn (Consultation $c) => new PlannedNotification(
                type: 'tutor_hour_reminder',
                phone: $c->tutor->phone,
                recipient: $c->tutor->name,
                body: $this->messages->tutorReminder($c, $this->minutesLeft($now, $c)),
                claim: fn () => Consultation::whereKey($c->id)->whereNull('tutor_reminded_at')->where('starts_at', $c->starts_at)->update(['tutor_reminded_at' => now()]) === 1,
                sessionStart: CarbonImmutable::instance($c->starts_at),
            ))
            ->values()
            ->all();
    }

    /** Waktunya pengingat singkat sudah tiba, belum terlambat, dan kabar jadwal dikirim sebelum waktu pengingat. */
    private function hourReminderDue(CarbonImmutable $now, CarbonImmutable $start, mixed $informedAt): bool
    {
        $at = $this->window->hourReminderAt($start);

        return $now->greaterThanOrEqualTo($at)
            && ! $this->window->isTooLate($now, $start)
            && $informedAt !== null
            && $informedAt->lessThan($at);
    }

    private function minutesLeft(CarbonImmutable $now, Consultation $c): int
    {
        return max(1, (int) round($now->diffInMinutes($c->starts_at, false)));
    }

    /** Peserta sesi terjadwal yang sudah dikonfirmasi dan belum menerima pengingat tertentu. */
    private function clientParticipants(CarbonImmutable $now, string $flag, CarbonImmutable $until)
    {
        return ConsultationParticipant::query()
            ->with(['client', 'consultation.tutor', 'consultation.participants.client'])
            ->whereNull($flag)
            ->whereNotNull('confirmation_sent_at')
            ->whereHas('consultation', fn ($q) => $q
                ->where('status', ConsultationStatus::Scheduled)
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<=', $until))
            ->get()
            ->filter(fn (ConsultationParticipant $p) => $p->client !== null);
    }

    private function claimParticipant(ConsultationParticipant $p, string $flag): bool
    {
        return ConsultationParticipant::whereKey($p->id)->whereNull($flag)->update([$flag => now()]) === 1;
    }
}
