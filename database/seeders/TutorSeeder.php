<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ExpertiseField;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 65 tutor contoh lengkap dengan akun login, bidang keahlian, dan jam tersedia.
 * Email: <nama-depan>.<nomor>@koedu.test. Nomor WA fiktif di rentang 62800 (tidak dipakai operator).
 */
class TutorSeeder extends Seeder
{
    /**
     * Pola jam tersedia, dibagi bergiliran ke tutor supaya jadwal tim bervariasi.
     * Format: day_of_week (1 = Senin) => [[mulai, selesai], ...]
     */
    private const AVAILABILITY_PATTERNS = [
        'weekday_office' => [1 => [['09:00', '12:00'], ['13:00', '17:00']], 2 => [['09:00', '12:00'], ['13:00', '17:00']], 3 => [['09:00', '12:00'], ['13:00', '17:00']], 4 => [['09:00', '12:00'], ['13:00', '17:00']], 5 => [['09:00', '11:30']]],
        'weekday_evening' => [1 => [['19:00', '22:00']], 2 => [['19:00', '22:00']], 3 => [['19:00', '22:00']], 4 => [['19:00', '22:00']], 5 => [['19:00', '22:00']]],
        'weekend' => [6 => [['08:00', '12:00'], ['13:00', '17:00']], 7 => [['08:00', '12:00'], ['13:00', '17:00']]],
        'mixed' => [2 => [['15:00', '18:00']], 4 => [['15:00', '18:00']], 6 => [['09:00', '15:00']]],
    ];

    public function run(): void
    {
        $tutors = require __DIR__.'/data/tutors.php';
        $fieldIds = ExpertiseField::pluck('id', 'name');
        $password = Hash::make(config('koedu.seed.user_password'));
        $patterns = array_values(self::AVAILABILITY_PATTERNS);

        DB::transaction(function () use ($tutors, $fieldIds, $password, $patterns) {
            foreach ($tutors as $index => [$name, $degree, $fields]) {
                $number = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $firstName = Str::of($name)->explode(' ')->first(fn ($part) => ! str_ends_with($part, '.'));

                $user = User::updateOrCreate(
                    ['email' => Str::lower(Str::ascii($firstName)).".{$number}@koedu.test"],
                    [
                        'name' => $name,
                        'role' => UserRole::Tutor,
                        'password' => $password,
                        'email_verified_at' => now(),
                        'is_active' => true,
                    ],
                );

                $tutor = Tutor::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'name' => $name,
                        'phone' => "628000000{$number}00",
                        'highest_degree' => $degree,
                        // Tutor S3 biasanya juga dosen tetap, jadi slotnya lebih sedikit.
                        'max_weekly_sessions' => $degree === 'S3' ? 10 : 15,
                        'is_active' => true,
                    ],
                );

                $tutor->expertiseFields()->sync(
                    collect($fields)->map(fn ($field) => $fieldIds[$field] ?? throw new \RuntimeException("Bidang tidak dikenal: {$field}"))
                );

                $tutor->availabilities()->delete();
                foreach ($patterns[$index % count($patterns)] as $day => $slots) {
                    foreach ($slots as [$start, $end]) {
                        $tutor->availabilities()->create(['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end]);
                    }
                }
            }
        });
    }
}
