<?php

namespace App\Services\Scheduling;

use App\Enums\ConsultationType;
use App\Enums\DegreeLevel;
use Carbon\CarbonImmutable;

/**
 * Hasil pembacaan chat oleh AI, sudah divalidasi. Nilai yang tidak masuk akal (tanggal sudah lewat,
 * jam atau jenjang tidak dikenal, durasi ekstrem) dibuang menjadi null supaya admin tidak menerima data keliru.
 */
final readonly class ScheduleExtraction
{
    public function __construct(
        public bool $isScheduleRequest,
        public float $confidence,
        public string $summary,
        public ?string $clientName,
        public ?string $degreeLevel,
        public ?string $fieldOfStudy,
        public ?string $serviceNote,
        public ?string $requestedDate,
        public ?string $requestedTime,
        public ?int $durationMinutes,
        public ?string $sessionType,
        public string $provider,
        public string $model,
    ) {}

    /** @param array<string, mixed> $data jawaban mentah AI */
    public static function fromAi(array $data, CarbonImmutable $today, string $provider, string $model): self
    {
        return new self(
            isScheduleRequest: filter_var($data['is_schedule_request'] ?? false, FILTER_VALIDATE_BOOLEAN),
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
            summary: self::text($data['summary'] ?? null, 500) ?? '',
            clientName: self::text($data['client_name'] ?? null, 255),
            degreeLevel: DegreeLevel::tryFrom((string) ($data['degree_level'] ?? ''))?->value,
            fieldOfStudy: self::text($data['field_of_study'] ?? null, 255),
            serviceNote: self::text($data['service_note'] ?? null, 500),
            requestedDate: self::date($data['requested_date'] ?? null, $today),
            requestedTime: self::time($data['requested_time'] ?? null),
            durationMinutes: self::duration($data['duration_minutes'] ?? null),
            sessionType: ConsultationType::tryFrom((string) ($data['session_type'] ?? ''))?->value,
            provider: $provider,
            model: $model,
        );
    }

    /**
     * Isian jadwal untuk disimpan di draf. Yang kosong tetap ada sebagai null supaya bentuknya konsisten.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return [
            'client_name' => $this->clientName,
            'degree_level' => $this->degreeLevel,
            'field_of_study' => $this->fieldOfStudy,
            'service_note' => $this->serviceNote,
            'requested_date' => $this->requestedDate,
            'requested_time' => $this->requestedTime,
            'duration_minutes' => $this->durationMinutes,
            'session_type' => $this->sessionType,
        ];
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** Hanya tanggal sah berformat YYYY-MM-DD yang belum lewat. */
    private static function date(mixed $value, CarbonImmutable $today): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value >= $today->toDateString() ? $value : null;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }

    private static function duration(mixed $value): ?int
    {
        $minutes = is_numeric($value) ? (int) $value : 0;

        return $minutes >= 15 && $minutes <= 480 ? $minutes : null;
    }
}
