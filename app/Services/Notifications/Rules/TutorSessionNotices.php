<?php

namespace App\Services\Notifications\Rules;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\Tutor;
use App\Services\Notifications\MessageTemplates;
use App\Services\Notifications\NotificationRule;
use App\Services\Notifications\PlannedNotification;
use Carbon\CarbonImmutable;

/**
 * Kabar sesi untuk tutor: jadwal baru, jadwal diubah (jam lama dan baru), sesi dialihkan ke tutor lain,
 * dan pembatalan. Yang dibatalkan atau dialihkan hanya dikabarkan ke tutor yang pernah diberi tahu.
 */
final class TutorSessionNotices implements NotificationRule
{
    public function __construct(private readonly MessageTemplates $messages) {}

    public function plan(CarbonImmutable $now): array
    {
        return [...$this->scheduled($now), ...$this->cancelled($now)];
    }

    /** @return list<PlannedNotification> */
    private function scheduled(CarbonImmutable $now): array
    {
        $planned = [];

        $sessions = Consultation::query()
            ->with(['tutor', 'participants.client'])
            ->where('status', ConsultationStatus::Scheduled)
            ->where('starts_at', '>', $now)
            ->whereNull('tutor_notified_at')
            ->orderBy('starts_at')
            ->get();

        foreach ($sessions as $c) {
            $oldStart = $c->tutor_notified_starts_at ? CarbonImmutable::instance($c->tutor_notified_starts_at) : null;
            $start = CarbonImmutable::instance($c->starts_at);
            $reassigned = $oldStart !== null && $c->tutor_notified_tutor_id !== null && $c->tutor_notified_tutor_id !== $c->tutor_id;

            // Tutor lama diberi tahu lebih dulu (urutan daftar penting: klaimnya membersihkan penanda lama).
            if ($reassigned && ($old = Tutor::find($c->tutor_notified_tutor_id))) {
                $planned[] = $this->relieve($c, $old, $oldStart, reassigned: true, type: 'tutor_reassigned');
            }

            $changed = $oldStart !== null && ! $reassigned;
            $unchanged = $changed && $oldStart->equalTo($start);

            $planned[] = new PlannedNotification(
                type: $changed ? 'tutor_changed' : 'tutor_new',
                phone: $c->tutor->phone,
                recipient: $c->tutor->name,
                // Waktu kembali ke yang sudah diketahui tutor: tidak ada yang perlu diberitahukan.
                body: $unchanged ? null : ($changed ? $this->messages->tutorChanged($c, $oldStart) : $this->messages->tutorNew($c)),
                claim: fn () => Consultation::whereKey($c->id)
                    ->whereNull('tutor_notified_at')
                    ->where('status', ConsultationStatus::Scheduled)
                    ->where('tutor_id', $c->tutor_id)
                    ->where('starts_at', $c->starts_at)
                    ->update([
                        'tutor_notified_at' => now(),
                        'tutor_notified_starts_at' => $c->starts_at,
                        'tutor_notified_tutor_id' => $c->tutor_id,
                    ]) === 1,
                sessionStart: $start,
            );
        }

        return $planned;
    }

    /** @return list<PlannedNotification> */
    private function cancelled(CarbonImmutable $now): array
    {
        return Consultation::query()
            ->with(['tutor', 'participants.client'])
            ->where('status', ConsultationStatus::Cancelled)
            ->whereNotNull('tutor_notified_starts_at')
            ->whereNull('tutor_cancel_notified_at')
            ->where('tutor_notified_starts_at', '>', $now)
            ->get()
            ->map(function (Consultation $c) {
                // Yang diberi tahu adalah tutor yang tercatat, bukan tutor saat ini (bisa sudah diganti).
                $tutor = Tutor::find($c->tutor_notified_tutor_id ?? $c->tutor_id) ?? $c->tutor;

                return $this->relieve($c, $tutor, CarbonImmutable::instance($c->tutor_notified_starts_at), reassigned: false, type: 'tutor_cancelled');
            })
            ->all();
    }

    private function relieve(Consultation $c, Tutor $tutor, CarbonImmutable $oldStart, bool $reassigned, string $type): PlannedNotification
    {
        return new PlannedNotification(
            type: $type,
            phone: $tutor->phone,
            recipient: $tutor->name,
            body: $this->messages->tutorRelieved($c, $tutor->name, $oldStart, $reassigned),
            claim: $reassigned
                // Setelah tutor lama diberi tahu, penanda dibersihkan supaya tutor baru menerima pesan "jadwal baru", bukan "diubah".
                ? fn () => Consultation::whereKey($c->id)
                    ->where('tutor_notified_tutor_id', $tutor->id)
                    ->update(['tutor_notified_tutor_id' => null, 'tutor_notified_starts_at' => null]) === 1
                : fn () => Consultation::whereKey($c->id)->whereNull('tutor_cancel_notified_at')->update(['tutor_cancel_notified_at' => now()]) === 1,
            sessionStart: $oldStart,
        );
    }
}
