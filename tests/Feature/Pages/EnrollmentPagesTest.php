<?php

namespace Tests\Feature\Pages;

use App\Enums\BenefitType;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Package;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class EnrollmentPagesTest extends PageTestCase
{
    private function package(bool $active = true): Package
    {
        $package = Package::factory()->create(['is_active' => $active, 'price' => 1_500_000, 'duration_days' => 30]);
        $package->benefits()->createMany([
            ['benefit_type' => BenefitType::ZoomSession, 'quantity' => 10],
            ['benefit_type' => BenefitType::Turnitin, 'quantity' => 2],
        ]);

        return $package;
    }

    public function test_admin_adds_package_with_copied_price_and_quotas(): void
    {
        $client = Client::factory()->create();
        $tutor = $this->anyDayTutor();

        $this->actingAs($this->admin())->post("/clients/{$client->id}/enrollments", [
            'package_id' => $this->package()->id,
            'starts_on' => $this->daysFromNow(0),
            'primary_tutor_id' => $tutor->id,
        ])->assertRedirect();

        $enrollment = $client->enrollments()->with('quotas')->first();
        $this->assertSame(1_500_000, $enrollment->price);
        $this->assertSame(PaymentStatus::Unpaid, $enrollment->payment_status);
        $this->assertSame($tutor->id, $enrollment->primary_tutor_id);
        $this->assertSame(10, $enrollment->quotaFor(BenefitType::ZoomSession)->total);
    }

    public function test_only_admin_can_add_packages_and_inactive_packages_are_rejected(): void
    {
        $client = Client::factory()->create();
        $payload = ['package_id' => $this->package()->id, 'starts_on' => $this->daysFromNow(0)];

        $this->actingAs($this->director())->post("/clients/{$client->id}/enrollments", $payload)->assertForbidden();
        $this->actingAs($this->anyDayTutor()->user)->post("/clients/{$client->id}/enrollments", $payload)->assertForbidden();

        $this->actingAs($this->admin())->post("/clients/{$client->id}/enrollments", ['package_id' => $this->package(active: false)->id, 'starts_on' => $this->daysFromNow(0)])
            ->assertSessionHasErrors('package_id');
        $this->assertSame(0, $client->enrollments()->count());
    }

    public function test_recording_payment_marks_paid_and_stores_private_proof(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $enrollment = $this->activeClientEnrollmentUnpaid();

        $this->actingAs($admin)->post("/enrollments/{$enrollment->id}/payment", [
            'payment_method' => 'Transfer BCA',
            'paid_on' => $this->daysFromNow(0),
            'proof' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertRedirect();

        $enrollment->refresh();
        $this->assertSame(PaymentStatus::Paid, $enrollment->payment_status);
        $this->assertSame($admin->id, $enrollment->payment_recorded_by);
        Storage::disk('local')->assertExists($enrollment->payment_proof_path);

        $this->actingAs($admin)->get("/enrollments/{$enrollment->id}/proof")->assertOk();
        $this->actingAs($this->anyDayTutor()->user)->get("/enrollments/{$enrollment->id}/proof")->assertForbidden();
    }

    public function test_payment_rejects_bad_file_and_future_date(): void
    {
        Storage::fake('local');
        $enrollment = $this->activeClientEnrollmentUnpaid();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/enrollments/{$enrollment->id}/payment", [
            'payment_method' => 'QRIS', 'paid_on' => $this->daysFromNow(0), 'proof' => UploadedFile::fake()->create('virus.exe', 10),
        ])->assertSessionHasErrors('proof');

        $this->actingAs($admin)->post("/enrollments/{$enrollment->id}/payment", [
            'payment_method' => 'QRIS', 'paid_on' => $this->daysFromNow(3),
        ])->assertSessionHasErrors('paid_on');

        $this->assertSame(PaymentStatus::Unpaid, $enrollment->refresh()->payment_status);
    }

    public function test_director_and_tutor_cannot_record_payment_and_admin_can_undo(): void
    {
        $enrollment = $this->activeClientEnrollmentUnpaid();
        $payload = ['payment_method' => 'QRIS', 'paid_on' => $this->daysFromNow(0)];

        $this->actingAs($this->director())->post("/enrollments/{$enrollment->id}/payment", $payload)->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->post("/enrollments/{$enrollment->id}/payment", $payload);
        $this->assertSame(PaymentStatus::Paid, $enrollment->refresh()->payment_status);

        $this->actingAs($admin)->delete("/enrollments/{$enrollment->id}/payment")->assertRedirect();
        $enrollment->refresh();
        $this->assertSame(PaymentStatus::Unpaid, $enrollment->payment_status);
        $this->assertNull($enrollment->paid_at);
    }

    public function test_quota_adjustment_stays_within_bounds(): void
    {
        $admin = $this->admin();
        $enrollment = $this->activeClientEnrollmentUnpaid();
        $turnitin = $enrollment->quotas()->where('benefit_type', BenefitType::Turnitin)->first(); // total 2

        $this->actingAs($admin)->patch("/enrollment-quotas/{$turnitin->id}", ['delta' => -1])->assertSessionHasErrors('delta');
        $this->actingAs($admin)->patch("/enrollment-quotas/{$turnitin->id}", ['delta' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch("/enrollment-quotas/{$turnitin->id}", ['delta' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch("/enrollment-quotas/{$turnitin->id}", ['delta' => 1])->assertSessionHasErrors('delta');

        $this->assertSame(2, $turnitin->refresh()->used);
        $this->actingAs($this->director())->patch("/enrollment-quotas/{$turnitin->id}", ['delta' => -1])->assertForbidden();
    }

    public function test_admin_can_cancel_a_package_and_change_its_tutor(): void
    {
        $enrollment = $this->activeClientEnrollmentUnpaid();
        $tutor = $this->anyDayTutor();
        $admin = $this->admin();

        $this->actingAs($admin)->put("/enrollments/{$enrollment->id}", ['primary_tutor_id' => $tutor->id])->assertRedirect();
        $this->assertSame($tutor->id, $enrollment->refresh()->primary_tutor_id);
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status, 'tanpa kunci status, status tidak berubah');

        $this->actingAs($admin)->put("/enrollments/{$enrollment->id}", ['status' => 'cancelled'])->assertRedirect();
        $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->refresh()->status);
        $this->assertSame($tutor->id, $enrollment->primary_tutor_id, 'tutor tidak terhapus saat hanya status yang dikirim');
    }

    private function activeClientEnrollmentUnpaid()
    {
        $client = $this->activeClient();
        $enrollment = $client->enrollments()->first();
        $enrollment->update(['payment_status' => PaymentStatus::Unpaid]);
        $enrollment->quotas()->create(['benefit_type' => BenefitType::Turnitin, 'total' => 2, 'used' => 0]);

        return $enrollment;
    }
}
