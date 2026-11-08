<?php

namespace App\Services\Scheduling;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationType;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\Tutor;
use Carbon\CarbonImmutable;

/**
 * Satu-satunya tempat aturan jadwal. Form admin, saran tutor, AI, dan n8n memakai pemeriksa ini.
 *
 * Ditolak (error): tutor atau client sudah punya sesi di jam yang sama, peserta melebihi kapasitas.
 * Peringatan (warning): di luar jam tersedia tutor, tutor melebihi batas sesi minggu itu,
 * client tanpa paket aktif/kuota, atau paket belum lunas.
 */
final class ScheduleChecker
{
    private const DAY_NAMES = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    public function __construct(private readonly EnrollmentResolver $enrollments) {}

    public function check(ScheduleInput $input, ?int $ignoreConsultationId = null): ScheduleCheckResult
    {
        $tz = config('app.timezone');
        $start = $input->startsAt->setTimezone($tz);
        $end = $input->endsAt->setTimezone($tz);

        $tutor = Tutor::with('availabilities')->find($input->tutorId);
        if ($tutor === null || ! $tutor->is_active) {
            return new ScheduleCheckResult(errors: ['Tutor tidak ditemukan atau sudah tidak aktif.']);
        }
        if ($end->lessThanOrEqualTo($start)) {
            return new ScheduleCheckResult(errors: ['Jam selesai harus setelah jam mulai.']);
        }

        $errors = [...$this->participantErrors($input), ...$this->conflictErrors($input, $tutor, $start, $end, $ignoreConsultationId)];
        $warnings = [
            ...$this->availabilityWarnings($tutor, $start, $end),
            ...$this->workloadWarnings($tutor, $start, $ignoreConsultationId),
            ...$this->enrollmentWarnings($input, $start),
        ];

        return new ScheduleCheckResult($errors, $warnings);
    }

    /** @return list<string> */
    private function participantErrors(ScheduleInput $input): array
    {
        $count = count($input->uniqueClientIds());

        return match (true) {
            $count === 0 => ['Pilih minimal satu client.'],
            $input->type === ConsultationType::Private && $count > 1 => ['Sesi privat hanya untuk satu client.'],
            $count > $input->capacity => ["Peserta ({$count}) melebihi kapasitas kelas ({$input->capacity})."],
            default => [],
        };
    }

    /** @return list<string> */
    private function conflictErrors(ScheduleInput $input, Tutor $tutor, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId): array
    {
        $errors = [];

        $clash = Consultation::overlapping($start, $end)
            ->where('tutor_id', $tutor->id)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->orderBy('starts_at')
            ->first();
        if ($clash !== null) {
            $errors[] = "Tutor {$tutor->name} sudah punya sesi pada {$this->describe($clash)}.";
        }

        $busyClients = ConsultationParticipant::query()
            ->with(['client:id,name', 'consultation:id,starts_at,ends_at'])
            ->whereIn('client_id', $input->uniqueClientIds())
            ->whereHas('consultation', fn ($q) => $q->overlapping($start, $end)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId)))
            ->get()
            ->unique('client_id');
        foreach ($busyClients as $participant) {
            $errors[] = "Client {$participant->client->name} sudah punya sesi pada {$this->describe($participant->consultation)}.";
        }

        return $errors;
    }

    /** @return list<string> */
    private function availabilityWarnings(Tutor $tutor, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($tutor->isAvailableBetween($start, $end)) {
            return [];
        }

        $slots = $tutor->availabilities->where('day_of_week', $start->dayOfWeekIso)->sortBy('start_time');
        $day = self::DAY_NAMES[$start->dayOfWeekIso];
        if ($slots->isEmpty()) {
            return ["Tutor {$tutor->name} tidak punya jam tersedia pada hari {$day}."];
        }

        $ranges = $slots->map(fn ($s) => substr($s->start_time, 0, 5).'-'.substr($s->end_time, 0, 5))->implode(', ');

        return ["Jam ini di luar jam tersedia tutor pada hari {$day} ({$ranges})."];
    }

    /** @return list<string> */
    private function workloadWarnings(Tutor $tutor, CarbonImmutable $start, ?int $ignoreId): array
    {
        $weekStart = $start->startOfWeek();
        $count = Consultation::query()
            ->where('tutor_id', $tutor->id)
            ->where('status', '!=', ConsultationStatus::Cancelled)
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $weekStart->addWeek())
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->count();

        if ($count + 1 <= $tutor->max_weekly_sessions) {
            return [];
        }

        return ["Tutor {$tutor->name} sudah punya {$count} sesi minggu ini (batas {$tutor->max_weekly_sessions})."];
    }

    /** @return list<string> */
    private function enrollmentWarnings(ScheduleInput $input, CarbonImmutable $start): array
    {
        if ($input->benefitType === null) {
            return [];
        }

        $names = Client::whereIn('id', $input->uniqueClientIds())->pluck('name', 'id');
        $warnings = [];

        foreach ($input->uniqueClientIds() as $clientId) {
            $name = $names[$clientId] ?? "Client #{$clientId}";
            $enrollment = $this->enrollments->usableFor($clientId, $input->benefitType, $start);

            if ($enrollment === null) {
                $warnings[] = "{$name} tidak punya paket aktif dengan sisa kuota \"{$input->benefitType->label()}\".";
            } elseif ($enrollment->payment_status === PaymentStatus::Unpaid) {
                $warnings[] = "Paket {$enrollment->package->name} milik {$name} belum lunas.";
            }
        }

        return $warnings;
    }

    private function describe(Consultation $consultation): string
    {
        $tz = config('app.timezone');

        return $consultation->starts_at->setTimezone($tz)->translatedFormat('j M Y H:i').'-'.$consultation->ends_at->setTimezone($tz)->format('H:i');
    }
}
