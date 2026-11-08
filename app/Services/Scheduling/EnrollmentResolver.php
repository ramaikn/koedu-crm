<?php

namespace App\Services\Scheduling;

use App\Enums\BenefitType;
use App\Models\Enrollment;
use Carbon\CarbonInterface;

/** Mencari paket client yang masih bisa dipakai untuk satu jenis layanan pada tanggal tertentu. */
final class EnrollmentResolver
{
    /**
     * Paket aktif dan belum kedaluwarsa dengan sisa kuota untuk layanan tersebut.
     * Bila ada beberapa, dipakai yang paling dulu berakhir supaya kuota tidak hangus.
     */
    public function usableFor(int $clientId, BenefitType $benefit, CarbonInterface $on): ?Enrollment
    {
        return Enrollment::query()
            ->where('client_id', $clientId)
            ->usableOn($on)
            ->with(['quotas', 'package:id,name'])
            ->orderBy('ends_on')
            ->orderBy('id')
            ->get()
            ->first(fn (Enrollment $enrollment) => $enrollment->quotaFor($benefit)?->hasRemaining() ?? false);
    }
}
