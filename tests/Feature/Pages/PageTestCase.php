<?php

namespace Tests\Feature\Pages;

use App\Actions\Enrollments\CreateEnrollment;
use App\Enums\BenefitType;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Package;
use App\Models\Tutor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Scheduling\SchedulingTestCase;

/**
 * Dasar test halaman. Memakai tanggal relatif terhadap hari ini supaya test tetap valid kapan pun dijalankan.
 * Catatan: halaman Inertia hanya bisa dirender bila aset frontend sudah di-build (bun run build).
 */
abstract class PageTestCase extends SchedulingTestCase
{
    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function director(): User
    {
        return User::factory()->director()->create();
    }

    /** Tutor yang tersedia setiap hari 08:00-20:00, supaya jadwal di hari apa pun tidak memicu peringatan jam. */
    protected function anyDayTutor(array $attributes = []): Tutor
    {
        $tutor = Tutor::factory()->create($attributes);
        foreach (range(1, 7) as $day) {
            $tutor->availabilities()->create(['day_of_week' => $day, 'start_time' => '08:00', 'end_time' => '20:00']);
        }

        return $tutor;
    }

    protected function daysFromNow(int $days): string
    {
        return CarbonImmutable::now('Asia/Makassar')->addDays($days)->toDateString();
    }

    /** Client dengan paket lunas yang mulai kemarin dan berlaku 60 hari. */
    protected function activeClient(int $zoomQuota = 5, ?Tutor $primaryTutor = null): Client
    {
        $client = Client::factory()->create();
        $package = Package::factory()->create(['duration_days' => 60]);
        $package->benefits()->create(['benefit_type' => BenefitType::ZoomSession, 'quantity' => $zoomQuota]);

        app(CreateEnrollment::class)
            ->handle($client, $package, CarbonImmutable::now('Asia/Makassar')->subDay()->startOfDay(), $primaryTutor)
            ->update(['payment_status' => PaymentStatus::Paid]);

        return $client;
    }

    /** @return array<string, mixed> data form jadwal privat untuk besok jam 10:00 */
    protected function scheduleForm(Tutor $tutor, Client $client, array $overrides = []): array
    {
        return array_merge([
            'type' => 'private',
            'tutor_id' => $tutor->id,
            'title' => 'Bimbingan Bab 1',
            'date' => $this->daysFromNow(1),
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'meeting_mode' => 'zoom',
            'benefit_type' => 'zoom_session',
            'client_ids' => [$client->id],
        ], $overrides);
    }
}
