<?php

namespace Database\Seeders;

use App\Models\ExpertiseField;
use Illuminate\Database\Seeder;

class ExpertiseFieldSeeder extends Seeder
{
    public function run(): void
    {
        $groups = require __DIR__.'/data/expertise_fields.php';

        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                ExpertiseField::updateOrCreate(['name' => $name], ['group' => $group]);
            }
        }
    }
}
