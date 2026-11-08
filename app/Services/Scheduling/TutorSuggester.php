<?php

namespace App\Services\Scheduling;

use App\Enums\ConsultationStatus;
use App\Enums\DegreeLevel;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\ExpertiseField;
use App\Models\Tutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Memberi peringkat tutor untuk sebuah sesi. Bobot ada di konstanta di bawah, mudah disetel tim bisnis.
 *
 * Skor = kecocokan bidang + jenjang + kesinambungan (tutor pembimbing client) + jam tersedia + beban minggu itu.
 * Tutor yang sudah punya sesi di jam yang sama tidak dimasukkan ke peringkat; yang bidangnya cocok
 * ditampilkan terpisah sebagai "cocok tapi bentrok" supaya admin tahu alasannya.
 */
final class TutorSuggester
{
    public const FIELD_EXACT = 50;

    public const FIELD_SAME_GROUP = 25;

    public const CURRENT_TUTOR = 20;

    public const DEGREE_MATCH = 10;

    public const WITHIN_AVAILABILITY = 25;

    public const OUTSIDE_AVAILABILITY = -25;

    /** Nilai maksimum bonus beban rendah. Tutor yang kosong mendapat penuh, yang penuh mendapat 0. */
    public const WORKLOAD_MAX = 15;

    private const MAX_ELIGIBLE = 8;

    private const MAX_BLOCKED = 3;

    /**
     * @return array{eligible: list<TutorSuggestion>, blocked: list<TutorSuggestion>}
     */
    public function suggest(
        ?int $clientId,
        ?int $expertiseFieldId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $ignoreConsultationId = null,
    ): array {
        $tz = config('app.timezone');
        $start = $start->setTimezone($tz);
        $end = $end->setTimezone($tz);

        $client = $clientId ? Client::find($clientId) : null;
        $field = ExpertiseField::find($expertiseFieldId ?? $client?->expertise_field_id);
        $currentTutorIds = $client ? $this->currentTutorIds($client, $start) : [];
        $busyTutorIds = $this->busyTutorIds($start, $end, $ignoreConsultationId);
        $weeklyLoad = $this->weeklyLoad($start, $ignoreConsultationId);

        $suggestions = Tutor::active()
            ->with(['expertiseFields', 'availabilities'])
            ->get()
            ->map(fn (Tutor $tutor) => $this->score($tutor, $field, $client?->degree_level, $currentTutorIds, $busyTutorIds, $weeklyLoad, $start, $end));

        $byScore = fn (TutorSuggestion $a, TutorSuggestion $b) => [$b->score, $a->name] <=> [$a->score, $b->name];

        return [
            'eligible' => $suggestions->filter(fn ($s) => $s->free)->sort($byScore)->take(self::MAX_ELIGIBLE)->values()->all(),
            // Hanya yang relevan, karena tutor tidak relevan yang sibuk tidak membantu admin.
            'blocked' => $suggestions->filter(fn ($s) => ! $s->free && $s->relevant)->sort($byScore)->take(self::MAX_BLOCKED)->values()->all(),
        ];
    }

    private function score(
        Tutor $tutor,
        ?ExpertiseField $field,
        ?DegreeLevel $clientLevel,
        array $currentTutorIds,
        array $busyTutorIds,
        array $weeklyLoad,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): TutorSuggestion {
        $score = 0;
        $reasons = [];
        // Relevan = bidangnya cocok atau sudah membimbing client ini. Dipakai untuk memilih tutor
        // sibuk yang layak ditampilkan; skor total tidak cocok untuk itu karena bonus jam tersedia ikut masuk.
        $relevant = false;

        if ($field !== null) {
            if ($tutor->expertiseFields->contains('id', $field->id)) {
                $score += self::FIELD_EXACT;
                $reasons[] = "Bidang sama persis ({$field->name})";
                $relevant = true;
            } elseif ($tutor->expertiseFields->contains('group', $field->group)) {
                $score += self::FIELD_SAME_GROUP;
                $reasons[] = "Satu rumpun ({$field->group})";
                $relevant = true;
            }
        }

        if (in_array($tutor->id, $currentTutorIds, true)) {
            $score += self::CURRENT_TUTOR;
            $reasons[] = 'Pembimbing client saat ini';
            $relevant = true;
        }

        if ($this->degreeMatches($tutor, $clientLevel)) {
            $score += self::DEGREE_MATCH;
            $reasons[] = "Jenjang {$tutor->highest_degree->value} sesuai";
        }

        $fits = $tutor->isAvailableBetween($start, $end);
        $score += $fits ? self::WITHIN_AVAILABILITY : self::OUTSIDE_AVAILABILITY;
        $reasons[] = $fits ? 'Tersedia di jam ini' : 'Di luar jam tersedia';

        $load = $weeklyLoad[$tutor->id] ?? 0;
        $max = max(1, $tutor->max_weekly_sessions);
        $score += (int) round(self::WORKLOAD_MAX * max(0, 1 - $load / $max));
        $reasons[] = "Beban minggu ini {$load}/{$tutor->max_weekly_sessions}";

        return new TutorSuggestion(
            tutorId: $tutor->id,
            name: $tutor->name,
            degree: $tutor->highest_degree->value,
            fields: $tutor->expertiseFields->pluck('name')->sort()->values()->all(),
            score: $score,
            reasons: $reasons,
            free: ! in_array($tutor->id, $busyTutorIds, true),
            relevant: $relevant,
            weeklyLoad: $load,
            weeklyMax: $tutor->max_weekly_sessions,
        );
    }

    /** Client S3 butuh tutor S3. Client S2 cocok dengan tutor S2 maupun S3. Jenjang lain tidak dinilai. */
    private function degreeMatches(Tutor $tutor, ?DegreeLevel $clientLevel): bool
    {
        return match ($clientLevel) {
            DegreeLevel::S3 => $tutor->highest_degree === DegreeLevel::S3,
            DegreeLevel::S2 => in_array($tutor->highest_degree, [DegreeLevel::S2, DegreeLevel::S3], true),
            default => false,
        };
    }

    /** @return list<int> */
    private function currentTutorIds(Client $client, CarbonImmutable $on): array
    {
        return Enrollment::where('client_id', $client->id)
            ->usableOn($on)
            ->whereNotNull('primary_tutor_id')
            ->pluck('primary_tutor_id')
            ->all();
    }

    /** @return list<int> */
    private function busyTutorIds(CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId): array
    {
        return Consultation::overlapping($start, $end)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->distinct()
            ->pluck('tutor_id')
            ->all();
    }

    /** @return array<int, int> tutor_id => jumlah sesi pada minggu yang sama */
    private function weeklyLoad(CarbonImmutable $start, ?int $ignoreId): array
    {
        $weekStart = $start->startOfWeek();

        return Consultation::query()
            ->select('tutor_id', DB::raw('count(*) as total'))
            ->where('status', '!=', ConsultationStatus::Cancelled)
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $weekStart->addWeek())
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->groupBy('tutor_id')
            ->pluck('total', 'tutor_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
