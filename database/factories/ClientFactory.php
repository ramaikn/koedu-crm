<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Enums\DegreeLevel;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => fake('id_ID')->name(),
            'phone' => '62800'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'university' => fake()->randomElement(['Universitas Negeri Makassar', 'Universitas Hasanuddin', 'UIN Alauddin Makassar', 'Universitas Muslim Indonesia', 'Universitas Gadjah Mada']),
            'study_program' => fake()->randomElement(['Manajemen', 'Akuntansi', 'Ilmu Hukum', 'Pendidikan Biologi', 'Keperawatan', 'Psikologi']),
            'degree_level' => DegreeLevel::S1,
            'status' => ClientStatus::Active,
            'source' => 'whatsapp',
        ];
    }
}
