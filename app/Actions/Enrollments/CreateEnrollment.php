<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\Tutor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mendaftarkan client ke sebuah paket.
 * Harga dan kuota disalin dari katalog saat ini, jadi perubahan katalog nanti
 * tidak mengubah paket yang sudah dibeli.
 */
final class CreateEnrollment
{
    public function handle(
        Client $client,
        Package $package,
        CarbonImmutable $startsOn,
        ?Tutor $primaryTutor = null,
        ?User $createdBy = null,
        ?string $notes = null,
    ): Enrollment {
        $package->loadMissing('benefits');

        $enrollment = DB::transaction(function () use ($client, $package, $startsOn, $primaryTutor, $createdBy, $notes) {
            $enrollment = Enrollment::create([
                'client_id' => $client->id,
                'package_id' => $package->id,
                'primary_tutor_id' => $primaryTutor?->id,
                'status' => EnrollmentStatus::Active,
                'starts_on' => $startsOn,
                // Masa aktif dihitung inklusif: paket 7 hari mulai Senin berakhir Minggu.
                'ends_on' => $startsOn->addDays($package->duration_days - 1),
                'price' => $package->price,
                'payment_status' => PaymentStatus::Unpaid,
                'notes' => $notes,
                'created_by' => $createdBy?->id,
            ]);

            $enrollment->quotas()->createMany(
                $package->benefits->map(fn ($benefit) => [
                    'benefit_type' => $benefit->benefit_type,
                    'total' => $benefit->quantity,
                    'used' => 0,
                ])->all(),
            );

            return $enrollment;
        });

        Log::info('Enrollment dibuat', [
            'enrollment_id' => $enrollment->id,
            'client_id' => $client->id,
            'package' => $package->code,
        ]);

        return $enrollment->load('quotas');
    }
}
