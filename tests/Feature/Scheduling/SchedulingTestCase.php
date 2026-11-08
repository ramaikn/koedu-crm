<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Enrollments\CreateEnrollment;
use App\Enums\BenefitType;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationType;
use App\Enums\MeetingMode;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\Tutor;
use App\Services\Scheduling\ScheduleInput;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dasar test jadwal. Tanggal tetap: 2026-11-09 adalah hari Senin, supaya hasil tidak bergantung pada hari test dijalankan.
 */
abstract class SchedulingTestCase extends TestCase
{
    use RefreshDatabase;

    protected const MONDAY = '2026-11-09';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Makassar']);
    }

    protected function at(string $time, string $date = self::MONDAY): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", 'Asia/Makassar');
    }

    /** Tutor dengan jam tersedia hari Senin 09:00-12:00 dan 13:00-17:00. */
    protected function tutor(array $attributes = [], bool $withAvailability = true): Tutor
    {
        $tutor = Tutor::factory()->create($attributes);

        if ($withAvailability) {
            $tutor->availabilities()->createMany([
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '17:00'],
            ]);
        }

        return $tutor;
    }

    protected function input(Tutor $tutor, array $clients, string $time = '10:00', int $minutes = 60, array $overrides = []): ScheduleInput
    {
        $start = $this->at($time);
        $values = [
            'type' => ConsultationType::Private,
            'tutorId' => $tutor->id,
            'title' => 'Bimbingan',
            'topic' => null,
            'startsAt' => $start,
            'endsAt' => $start->addMinutes($minutes),
            'meetingMode' => MeetingMode::Zoom,
            'meetingUrl' => null,
            'location' => null,
            'capacity' => 1,
            'benefitType' => BenefitType::ZoomSession,
            'clientIds' => array_map(fn (Client $c) => $c->id, $clients),
        ];

        return new ScheduleInput(...array_merge($values, $overrides));
    }

    /** Client dengan paket aktif (lunas) berisi kuota Zoom. */
    protected function clientWithPackage(int $zoomQuota = 5, bool $paid = true, ?Tutor $primaryTutor = null): Client
    {
        $client = Client::factory()->create();
        $package = Package::factory()->create();
        $package->benefits()->create(['benefit_type' => BenefitType::ZoomSession, 'quantity' => $zoomQuota]);

        $enrollment = app(CreateEnrollment::class)->handle($client, $package, CarbonImmutable::parse('2026-11-01'), $primaryTutor);
        if ($paid) {
            $enrollment->update(['payment_status' => PaymentStatus::Paid]);
        }

        return $client;
    }

    protected function makeSession(Tutor $tutor, string $time = '10:00', int $minutes = 60, array $clients = [], array $attributes = []): Consultation
    {
        $start = $this->at($time);
        $consultation = Consultation::create(array_merge([
            'type' => ConsultationType::Private,
            'tutor_id' => $tutor->id,
            'title' => 'Sesi',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($minutes),
            'benefit_type' => BenefitType::ZoomSession,
            'status' => ConsultationStatus::Scheduled,
        ], $attributes));

        foreach ($clients as $client) {
            $consultation->participants()->create([
                'client_id' => $client->id,
                // Seperti sesi sungguhan: terhubung ke paket client bila ia punya.
                'enrollment_id' => $client->enrollments()->value('id'),
            ]);
        }

        return $consultation;
    }

    protected function enrollmentOf(Client $client): Enrollment
    {
        return $client->enrollments()->with('quotas')->firstOrFail();
    }
}
