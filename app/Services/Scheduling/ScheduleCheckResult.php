<?php

namespace App\Services\Scheduling;

/**
 * Hasil pemeriksaan jadwal.
 * Error = jadwal ditolak (bentrok, kapasitas). Warning = boleh dilanjutkan setelah admin mengonfirmasi.
 */
final readonly class ScheduleCheckResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array{errors: list<string>, warnings: list<string>} */
    public function toArray(): array
    {
        return ['errors' => $this->errors, 'warnings' => $this->warnings];
    }
}
