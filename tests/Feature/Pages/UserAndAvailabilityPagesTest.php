<?php

namespace Tests\Feature\Pages;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Tutor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/** Kelola pengguna (director), jam tersedia milik tutor, dan paket kedaluwarsa otomatis. */
class UserAndAvailabilityPagesTest extends PageTestCase
{
    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Admin Baru',
            'email' => 'Admin.Baru@koedu.test',
            'role' => 'admin',
            'phone' => '',
            'is_active' => true,
            'password' => 'rahasia-panjang-123',
            'tutor_id' => null,
        ], $overrides);
    }

    public function test_only_director_opens_user_pages(): void
    {
        $this->actingAs($this->director())->get('/users')->assertOk()->assertInertia(fn (Assert $p) => $p->component('users/index'));
        $this->actingAs($this->admin())->get('/users')->assertForbidden();
        $this->actingAs(Tutor::factory()->create()->user)->get('/users/create')->assertForbidden();
    }

    public function test_director_creates_admin_account_that_can_log_in(): void
    {
        $this->actingAs($this->director())->post('/users', $this->userPayload())->assertRedirect('/users');

        $user = User::firstWhere('email', 'admin.baru@koedu.test');
        $this->assertNotNull($user, 'email disimpan huruf kecil');
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('rahasia-panjang-123', $user->password));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_tutor_account_must_link_to_a_free_tutor_profile(): void
    {
        $director = $this->director();
        $free = Tutor::factory()->create(['user_id' => null]);
        $taken = Tutor::factory()->create();

        $this->actingAs($director)->post('/users', $this->userPayload(['role' => 'tutor', 'email' => 'a@koedu.test']))->assertSessionHasErrors('tutor_id');
        $this->actingAs($director)->post('/users', $this->userPayload(['role' => 'tutor', 'email' => 'b@koedu.test', 'tutor_id' => $taken->id]))->assertSessionHasErrors('tutor_id');

        $this->actingAs($director)->post('/users', $this->userPayload(['role' => 'tutor', 'email' => 'c@koedu.test', 'tutor_id' => $free->id]))->assertSessionHasNoErrors();
        $this->assertSame(User::firstWhere('email', 'c@koedu.test')->id, $free->fresh()->user_id);
    }

    public function test_changing_role_away_from_tutor_unlinks_profile_and_keeps_password(): void
    {
        $tutor = Tutor::factory()->create();
        $user = $tutor->user;
        $oldHash = $user->password;

        $this->actingAs($this->director())->put("/users/{$user->id}", $this->userPayload([
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'admin',
            'password' => '',
            'tutor_id' => $tutor->id,
        ]))->assertSessionHasNoErrors();

        $this->assertNull($tutor->fresh()->user_id);
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertSame($oldHash, $user->fresh()->password, 'password kosong berarti tidak diganti');
    }

    public function test_director_cannot_lock_themselves_out(): void
    {
        $director = $this->director();

        $this->actingAs($director)->put("/users/{$director->id}", $this->userPayload([
            'email' => $director->email,
            'role' => 'admin',
            'is_active' => false,
            'password' => '',
        ]))->assertSessionHasErrors(['role', 'is_active']);

        $this->assertTrue($director->fresh()->isDirector());
        $this->assertTrue($director->fresh()->is_active);
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $admin->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_tutor_updates_own_availability(): void
    {
        $tutor = $this->anyDayTutor();

        $this->actingAs($tutor->user)->get('/my-availability')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('my-availability')->has('availabilities', 7));

        $this->actingAs($tutor->user)->put('/my-availability', ['availabilities' => [
            ['day_of_week' => 2, 'start_time' => '13:00', 'end_time' => '16:00'],
        ]])->assertRedirect('/my-availability');

        $slots = $tutor->availabilities()->get();
        $this->assertCount(1, $slots);
        $this->assertSame(2, $slots->first()->day_of_week);
    }

    public function test_availability_rejects_overlap_and_other_roles(): void
    {
        $tutor = $this->anyDayTutor();

        $this->actingAs($tutor->user)->put('/my-availability', ['availabilities' => [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['day_of_week' => 1, 'start_time' => '11:00', 'end_time' => '14:00'],
        ]])->assertSessionHasErrors('availabilities');
        $this->assertSame(7, $tutor->availabilities()->count(), 'data lama tidak berubah saat ditolak');

        $this->actingAs($this->admin())->get('/my-availability')->assertForbidden();
    }

    public function test_tutor_account_without_profile_sees_friendly_page(): void
    {
        $user = User::factory()->tutor()->create();

        $this->actingAs($user)->get('/my-availability')->assertOk()->assertInertia(fn (Assert $p) => $p->where('availabilities', null));
        $this->actingAs($user)->put('/my-availability', ['availabilities' => []])->assertNotFound();
    }

    public function test_expire_command_marks_only_past_active_enrollments(): void
    {
        $client = $this->activeClient();
        $enrollment = Enrollment::where('client_id', $client->id)->first();
        $today = CarbonImmutable::now()->toDateString();

        // Berakhir hari ini: masih berlaku sepanjang hari.
        $enrollment->update(['ends_on' => $today]);
        $this->artisan('enrollments:expire')->assertSuccessful();
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);

        $enrollment->update(['ends_on' => CarbonImmutable::now()->subDay()->toDateString()]);
        $this->artisan('enrollments:expire')->assertSuccessful();
        $this->assertSame(EnrollmentStatus::Expired, $enrollment->fresh()->status);

        // Paket yang sudah selesai tidak diubah jadi kedaluwarsa.
        $enrollment->update(['status' => EnrollmentStatus::Completed]);
        $this->artisan('enrollments:expire')->assertSuccessful();
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }
}
