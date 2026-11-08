<?php

namespace Tests\Feature\Pages;

use App\Enums\BenefitType;
use App\Models\ExpertiseField;
use App\Models\Package;
use App\Models\Tutor;
use Inertia\Testing\AssertableInertia as Assert;

class TutorAndPackagePagesTest extends PageTestCase
{
    private function tutorPayload(array $overrides = []): array
    {
        $field = ExpertiseField::firstOrCreate(['name' => 'Akuntansi'], ['group' => 'Ekonomi']);

        return array_merge([
            'name' => 'Dewi Lestari',
            'phone' => '0813-1111-2222',
            'highest_degree' => 'S2',
            'max_weekly_sessions' => 12,
            'is_active' => true,
            'expertise_field_ids' => [$field->id],
            'availabilities' => [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00']],
        ], $overrides);
    }

    public function test_staff_lists_tutors_and_tutors_are_blocked(): void
    {
        $tutor = $this->anyDayTutor();

        $this->actingAs($this->admin())->get('/tutors')->assertOk()->assertInertia(fn (Assert $p) => $p->component('tutors/index')->has('tutors.data', 1));
        $this->actingAs($this->director())->get('/tutors')->assertOk();
        $this->actingAs($tutor->user)->get('/tutors')->assertForbidden();
    }

    public function test_tutor_list_filters_by_field_and_degree(): void
    {
        $akuntansi = ExpertiseField::create(['name' => 'Akuntansi', 'group' => 'Ekonomi']);
        $hukum = ExpertiseField::create(['name' => 'Hukum', 'group' => 'Hukum']);
        $a = $this->anyDayTutor(['name' => 'Ani', 'highest_degree' => 'S3']);
        $b = $this->anyDayTutor(['name' => 'Budi']);
        $a->expertiseFields()->attach($akuntansi);
        $b->expertiseFields()->attach($hukum);

        $this->actingAs($this->admin())->get("/tutors?field={$akuntansi->id}")->assertInertia(fn (Assert $p) => $p->has('tutors.data', 1)->where('tutors.data.0.name', 'Ani'));
        $this->actingAs($this->admin())->get('/tutors?degree=S2')->assertInertia(fn (Assert $p) => $p->has('tutors.data', 1)->where('tutors.data.0.name', 'Budi'));
    }

    public function test_create_tutor_syncs_fields_and_availability(): void
    {
        $this->actingAs($this->admin())->post('/tutors', $this->tutorPayload([
            'availabilities' => [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '17:00'],
            ],
        ]))->assertRedirect();

        $tutor = Tutor::firstWhere('name', 'Dewi Lestari');
        $this->assertSame('6281311112222', $tutor->phone);
        $this->assertCount(1, $tutor->expertiseFields);
        $this->assertCount(2, $tutor->availabilities);
        $this->assertNull($tutor->user_id, 'akun login dibuat director, bukan lewat form tutor');
    }

    public function test_tutor_validation_rules(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/tutors', $this->tutorPayload(['expertise_field_ids' => []]))->assertSessionHasErrors('expertise_field_ids');
        $this->actingAs($admin)->post('/tutors', $this->tutorPayload(['highest_degree' => 'S1']))->assertSessionHasErrors('highest_degree');
        $this->actingAs($admin)->post('/tutors', $this->tutorPayload(['availabilities' => [['day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '09:00']]]))
            ->assertSessionHasErrors('availabilities');
        $this->actingAs($admin)->post('/tutors', $this->tutorPayload(['availabilities' => [
            ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['day_of_week' => 2, 'start_time' => '11:00', 'end_time' => '14:00'],
        ]]))->assertSessionHasErrors('availabilities');
        $this->assertSame(0, Tutor::count());
    }

    public function test_update_replaces_availability_and_keeps_own_phone(): void
    {
        $tutor = $this->anyDayTutor(['phone' => '6281311112222']);

        $this->actingAs($this->director())->put("/tutors/{$tutor->id}", $this->tutorPayload(['phone' => '081311112222', 'is_active' => false]))->assertRedirect();

        $tutor->refresh();
        $this->assertFalse($tutor->is_active);
        $this->assertCount(1, $tutor->availabilities, 'jam lama diganti seluruhnya');
    }

    private function packagePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'TEST-1M', 'category' => 'premium', 'name' => 'Paket Uji', 'schedule_type' => 'flexible', 'delivery' => 'private',
            'duration_days' => 30, 'price' => 1_000_000, 'list_price' => 2_000_000, 'is_active' => true,
            'benefits' => [['benefit_type' => 'zoom_session', 'quantity' => 10], ['benefit_type' => 'essay_review', 'quantity' => null]],
        ], $overrides);
    }

    public function test_admin_views_catalog_but_only_director_edits(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/packages')->assertOk()->assertInertia(fn (Assert $p) => $p->component('packages/index')->where('can_edit', false));
        $this->actingAs($admin)->get('/packages/create')->assertForbidden();
        $this->actingAs($admin)->post('/packages', $this->packagePayload())->assertForbidden();

        $this->actingAs($this->director())->get('/packages')->assertInertia(fn (Assert $p) => $p->where('can_edit', true));
    }

    public function test_director_creates_package_with_benefits_including_unlimited(): void
    {
        $this->actingAs($this->director())->post('/packages', $this->packagePayload())->assertRedirect('/packages');

        $package = Package::firstWhere('code', 'TEST-1M');
        $this->assertSame(10, $package->benefits->firstWhere('benefit_type', BenefitType::ZoomSession)->quantity);
        $this->assertNull($package->benefits->firstWhere('benefit_type', BenefitType::EssayReview)->quantity);
    }

    public function test_package_validation_rules(): void
    {
        $director = $this->director();

        $this->actingAs($director)->post('/packages', $this->packagePayload(['list_price' => 500_000]))->assertSessionHasErrors('list_price');
        $this->actingAs($director)->post('/packages', $this->packagePayload(['delivery' => 'group']))->assertSessionHasErrors('max_participants');
        $this->actingAs($director)->post('/packages', $this->packagePayload(['code' => 'kode salah!']))->assertSessionHasErrors('code');
        $this->actingAs($director)->post('/packages', $this->packagePayload(['benefits' => [
            ['benefit_type' => 'zoom_session', 'quantity' => 1], ['benefit_type' => 'zoom_session', 'quantity' => 2],
        ]]))->assertSessionHasErrors('benefits.0.benefit_type');
        $this->assertSame(0, Package::count());
    }

    public function test_updating_a_package_does_not_change_purchased_ones(): void
    {
        $director = $this->director();
        $client = $this->activeClient(zoomQuota: 5);
        $enrollment = $client->enrollments()->first();
        $package = $enrollment->package;

        $this->actingAs($director)->put("/packages/{$package->id}", $this->packagePayload([
            'code' => $package->code, 'price' => 9_000_000, 'list_price' => 10_000_000, 'benefits' => [['benefit_type' => 'zoom_session', 'quantity' => 99]],
        ]))->assertRedirect('/packages');

        $this->assertSame(9_000_000, $package->refresh()->price);
        $this->assertSame(99, $package->benefits()->first()->quantity);
        $this->assertSame(5, $enrollment->refresh()->quotaFor(BenefitType::ZoomSession)->total);
        $this->assertNotSame(9_000_000, $enrollment->price);
    }
}
