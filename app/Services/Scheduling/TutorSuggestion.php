<?php

namespace App\Services\Scheduling;

/** Satu tutor dalam daftar saran, lengkap dengan alasan skornya supaya admin bisa menilai sendiri. */
final readonly class TutorSuggestion
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $fields
     */
    public function __construct(
        public int $tutorId,
        public string $name,
        public string $degree,
        public array $fields,
        public int $score,
        public array $reasons,
        /** Tutor tidak punya sesi lain di jam yang diminta. */
        public bool $free,
        /** Bidangnya cocok atau sudah membimbing client ini. */
        public bool $relevant,
        public int $weeklyLoad,
        public int $weeklyMax,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tutor_id' => $this->tutorId,
            'name' => $this->name,
            'degree' => $this->degree,
            'fields' => $this->fields,
            'score' => $this->score,
            'reasons' => $this->reasons,
            'free' => $this->free,
            'weekly_load' => $this->weeklyLoad,
            'weekly_max' => $this->weeklyMax,
        ];
    }
}
