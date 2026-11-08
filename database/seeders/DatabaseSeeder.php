<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Data referensi: aman dijalankan di semua lingkungan dan bisa diulang.
        $this->call([
            ExpertiseFieldSeeder::class,
            PackageSeeder::class,
        ]);

        // Akun contoh dan data demo hanya untuk lokal, jangan sampai masuk produksi.
        if (app()->environment('local', 'testing')) {
            $this->call([
                StaffUserSeeder::class,
                TutorSeeder::class,
                DemoDataSeeder::class,
            ]);
        }
    }
}
