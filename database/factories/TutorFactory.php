<?php

namespace Database\Factories;

use App\Enums\DegreeLevel;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tutor> */
class TutorFactory extends Factory
{
    protected $model = Tutor::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->tutor(),
            'name' => fake('id_ID')->name(),
            // Rentang 62800... tidak dipakai operator, jadi aman sebagai nomor fiktif.
            'phone' => '62800'.fake()->unique()->numerify('########'),
            'highest_degree' => DegreeLevel::S2,
            'max_weekly_sessions' => 15,
            'is_active' => true,
        ];
    }
}
