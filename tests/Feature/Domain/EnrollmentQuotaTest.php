<?php

namespace Tests\Feature\Domain;

use App\Actions\Consultations\CompleteConsultation;
use App\Actions\Enrollments\CreateEnrollment;
use App\Enums\AttendanceStatus;
use App\Enums\BenefitType;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationType;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\Tutor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_copies_price_and_quotas_from_package(): void
    {
        $enrollment = $this->enroll(zoomQuota: 10);

        $this->assertSame(1_000_000, $enrollment->price);
        $this->assertSame('2026-11-01', $enrollment->starts_on->toDateString());
        // Paket 30 hari mulai 1 Nov berakhir 30 Nov (inklusif).
        $this->assertSame('2026-11-30', $enrollment->ends_on->toDateString());
        $this->assertSame(10, $enrollment->quotaFor(BenefitType::ZoomSession)->total);
        $this->assertNull($enrollment->quotaFor(BenefitType::EssayReview)->total, 'kuota tanpa batas tetap null');
    }

    public function test_catalog_change_does_not_affect_existing_enrollment(): void
    {
        $enrollment = $this->enroll(zoomQuota: 10);

        $enrollment->package->update(['price' => 2_000_000]);
        $enrollment->package->benefits()->where('benefit_type', BenefitType::ZoomSession)->update(['quantity' => 99]);

        $enrollment->refresh()->load('quotas');
        $this->assertSame(1_000_000, $enrollment->price);
        $this->assertSame(10, $enrollment->quotaFor(BenefitType::ZoomSession)->total);
    }

    public function test_completing_session_deducts_quota_once(): void
    {
        $enrollment = $this->enroll(zoomQuota: 10);
        $consultation = $this->sessionFor($enrollment);
        $action = app(CompleteConsultation::class);

        $action->handle($consultation);
        $action->handle($consultation); // klik "Selesai" dua kali

        $this->assertSame(ConsultationStatus::Completed, $consultation->refresh()->status);
        $this->assertSame(1, $enrollment->quotaFor(BenefitType::ZoomSession)->refresh()->used);
    }

    public function test_absent_client_is_not_deducted_by_default(): void
    {
        $enrollment = $this->enroll(zoomQuota: 10);
        $consultation = $this->sessionFor($enrollment);
        $participant = $consultation->participants()->first();

        app(CompleteConsultation::class)->handle($consultation, [$participant->id => AttendanceStatus::Absent]);

        $this->assertSame(0, $enrollment->quotaFor(BenefitType::ZoomSession)->refresh()->used);
        $this->assertSame(AttendanceStatus::Absent, $participant->refresh()->attendance);
    }

    public function test_absent_client_is_deducted_when_policy_enabled(): void
    {
        config(['koedu.quota.deduct_on_absent' => true]);
        $enrollment = $this->enroll(zoomQuota: 10);
        $consultation = $this->sessionFor($enrollment);
        $participant = $consultation->participants()->first();

        app(CompleteConsultation::class)->handle($consultation, [$participant->id => AttendanceStatus::Absent]);

        $this->assertSame(1, $enrollment->quotaFor(BenefitType::ZoomSession)->refresh()->used);
    }

    public function test_used_quota_never_exceeds_total(): void
    {
        $enrollment = $this->enroll(zoomQuota: 1);

        app(CompleteConsultation::class)->handle($this->sessionFor($enrollment));
        app(CompleteConsultation::class)->handle($this->sessionFor($enrollment, dayOffset: 1));

        $quota = $enrollment->quotaFor(BenefitType::ZoomSession)->refresh();
        $this->assertSame(1, $quota->used);
        $this->assertSame(0, $quota->remaining());
    }

    public function test_cancelled_session_cannot_be_completed(): void
    {
        $consultation = $this->sessionFor($this->enroll(zoomQuota: 5));
        $consultation->update(['status' => ConsultationStatus::Cancelled]);

        $this->expectException(ValidationException::class);
        app(CompleteConsultation::class)->handle($consultation);
    }

    private function enroll(int $zoomQuota): Enrollment
    {
        $package = Package::factory()->create();
        $package->benefits()->createMany([
            ['benefit_type' => BenefitType::ZoomSession, 'quantity' => $zoomQuota],
            ['benefit_type' => BenefitType::EssayReview, 'quantity' => null],
        ]);

        return app(CreateEnrollment::class)->handle(
            Client::factory()->create(),
            $package,
            CarbonImmutable::parse('2026-11-01'),
            Tutor::factory()->create(),
        );
    }

    private function sessionFor(Enrollment $enrollment, int $dayOffset = 0): Consultation
    {
        $start = CarbonImmutable::parse('2026-11-03 10:00')->addDays($dayOffset);
        $consultation = Consultation::create([
            'type' => ConsultationType::Private,
            'tutor_id' => $enrollment->primary_tutor_id,
            'title' => 'Bimbingan',
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'benefit_type' => BenefitType::ZoomSession,
        ]);
        $consultation->participants()->create(['client_id' => $enrollment->client_id, 'enrollment_id' => $enrollment->id]);

        return $consultation;
    }
}
