<?php

namespace App\Services\Notifications\Rules;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\Tutor;
use App\Services\Notifications\MessageTemplates;
use App\Services\Notifications\NotificationRule;
use App\Services\Notifications\PlannedNotification;
use Carbon\CarbonImmutable;

/**
 * Kabar sesi untuk client: konfirmasi jadwal, perubahan (jam lama dan baru), dan pembatalan.
 * Pembatalan hanya dikabarkan ke client yang pernah menerima konfirmasi.
 */
final class ClientSessionNotices implements NotificationRule
{
    public function __construct(private readonly MessageTemplates $messages) {}

    public function plan(CarbonImmutable $now): array
    {
        return [...$this->confirmations($now), ...$this->cancellations($now)];
    }

    /** @return list<PlannedNotification> */
    private function confirmations(CarbonImmutable $now): array
    {
        return ConsultationParticipant::query()
            ->with(['client', 'consultation.tutor', 'consultation.participants.client'])
            ->whereNull('confirmation_sent_at')
            ->whereHas('consultation', fn ($q) => $q->where('status', ConsultationStatus::Scheduled)->where('starts_at', '>', $now))
            ->get()
            ->filter(fn (ConsultationParticipant $p) => $p->client !== null)
            ->map(function (ConsultationParticipant $p) {
                $c = $p->consultation;
                $start = CarbonImmutable::instance($c->starts_at);
                $oldStart = $p->confirmed_starts_at ? CarbonImmutable::instance($p->confirmed_starts_at) : null;
                $sameTime = $oldStart !== null && $oldStart->equalTo($start);
                $sameTutor = $p->confirmed_tutor_id === null || $p->confirmed_tutor_id === $c->tutor_id;

                return new PlannedNotification(
                    type: $oldStart !== null ? 'client_changed' : 'client_confirmation',
                    phone: $p->client->phone,
                    recipient: $p->client->name,
                    // Waktu dan tutor kembali ke yang sudah diketahui client: tidak ada yang perlu diberitahukan.
                    body: $sameTime && $sameTutor ? null : $this->clientBody($p, $c, $oldStart, $sameTime),
                    claim: fn () => ConsultationParticipant::whereKey($p->id)
                        ->whereNull('confirmation_sent_at')
                        ->update(['confirmation_sent_at' => now(), 'confirmed_starts_at' => $c->starts_at, 'confirmed_tutor_id' => $c->tutor_id]) === 1,
                    sessionStart: $start,
                );
            })
            ->values()
            ->all();
    }

    private function clientBody(ConsultationParticipant $p, Consultation $c, ?CarbonImmutable $oldStart, bool $sameTime): string
    {
        if ($oldStart === null) {
            return $this->messages->clientConfirmation($c, $p->client->name);
        }

        // Hanya tutor yang berganti: pesan khusus dengan nama tutor sebelumnya.
        if ($sameTime) {
            return $this->messages->clientTutorChanged($c, $p->client->name, Tutor::find($p->confirmed_tutor_id)?->name ?? 'tutor sebelumnya');
        }

        return $this->messages->clientChanged($c, $p->client->name, $oldStart);
    }

    /** @return list<PlannedNotification> */
    private function cancellations(CarbonImmutable $now): array
    {
        return ConsultationParticipant::query()
            ->with(['client', 'consultation.tutor'])
            ->whereNotNull('confirmed_starts_at')
            ->whereNull('cancel_notified_at')
            ->where('confirmed_starts_at', '>', $now)
            ->whereHas('consultation', fn ($q) => $q->where('status', ConsultationStatus::Cancelled))
            ->get()
            ->filter(fn (ConsultationParticipant $p) => $p->client !== null)
            ->map(fn (ConsultationParticipant $p) => new PlannedNotification(
                type: 'client_cancelled',
                phone: $p->client->phone,
                recipient: $p->client->name,
                body: $this->messages->clientCancelled($p->consultation, $p->client->name, CarbonImmutable::instance($p->confirmed_starts_at)),
                claim: fn () => ConsultationParticipant::whereKey($p->id)->whereNull('cancel_notified_at')->update(['cancel_notified_at' => now()]) === 1,
                sessionStart: CarbonImmutable::instance($p->confirmed_starts_at),
            ))
            ->values()
            ->all();
    }
}
