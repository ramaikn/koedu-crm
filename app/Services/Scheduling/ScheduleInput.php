<?php

namespace App\Services\Scheduling;

use App\Enums\BenefitType;
use App\Enums\ConsultationType;
use App\Enums\MeetingMode;
use Carbon\CarbonImmutable;

/** Data sesi yang akan dibuat atau diubah. Waktu selalu dalam zona waktu aplikasi. */
final readonly class ScheduleInput
{
    public function __construct(
        public ConsultationType $type,
        public int $tutorId,
        public string $title,
        public ?string $topic,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public MeetingMode $meetingMode,
        public ?string $meetingUrl,
        public ?string $location,
        public int $capacity,
        /** Kuota yang dipotong dari paket peserta saat sesi selesai. */
        public ?BenefitType $benefitType,
        /** @var list<int> */
        public array $clientIds,
        public ?int $scheduleDraftId = null,
    ) {}

    /**
     * Membentuk input dari data form (date, start_time, duration_minutes, dan seterusnya).
     * Mengembalikan null bila data masih belum lengkap, dipakai pemeriksaan jadwal langsung di form.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): ?self
    {
        foreach (['type', 'tutor_id', 'date', 'start_time', 'duration_minutes', 'client_ids'] as $required) {
            if (empty($data[$required])) {
                return null;
            }
        }

        $type = ConsultationType::tryFrom((string) $data['type']);
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['start_time']}", config('app.timezone'));
        if ($type === null || $start === false) {
            return null;
        }

        $clientIds = array_map('intval', (array) $data['client_ids']);

        return new self(
            type: $type,
            tutorId: (int) $data['tutor_id'],
            title: trim((string) ($data['title'] ?? '')) ?: ($type === ConsultationType::Group ? 'Kelas grup' : 'Sesi bimbingan'),
            topic: ($data['topic'] ?? null) ?: null,
            startsAt: $start,
            endsAt: $start->addMinutes((int) $data['duration_minutes']),
            meetingMode: MeetingMode::tryFrom((string) ($data['meeting_mode'] ?? '')) ?? MeetingMode::Zoom,
            meetingUrl: ($data['meeting_url'] ?? null) ?: null,
            location: ($data['location'] ?? null) ?: null,
            // Sesi privat selalu 1 peserta. Kelas grup memakai kapasitas dari form (bawaan 20).
            capacity: $type === ConsultationType::Private ? 1 : max(1, (int) ($data['capacity'] ?? 20)),
            benefitType: BenefitType::tryFrom((string) ($data['benefit_type'] ?? '')),
            clientIds: $clientIds,
        );
    }

    /** @return list<int> */
    public function uniqueClientIds(): array
    {
        return array_values(array_unique($this->clientIds));
    }
}
