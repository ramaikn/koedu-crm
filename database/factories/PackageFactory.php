<?php

namespace Database\Factories;

use App\Enums\DeliveryMode;
use App\Enums\PackageCategory;
use App\Enums\ScheduleType;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'code' => 'TEST-'.fake()->unique()->numerify('####'),
            'category' => PackageCategory::Premium,
            'name' => 'Paket Uji',
            'schedule_type' => ScheduleType::Flexible,
            'delivery' => DeliveryMode::Private,
            'duration_days' => 30,
            'price' => 1_000_000,
            'is_active' => true,
        ];
    }
}
