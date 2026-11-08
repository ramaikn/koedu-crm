<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Akun contoh director dan admin. Password dari SEED_USER_PASSWORD (lihat .env.example). */
class StaffUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make(config('koedu.seed.user_password'));

        $staff = [
            ['name' => 'Direktur Utama', 'email' => 'director@koedu.test', 'role' => UserRole::Director],
            ['name' => 'Admin CS 1', 'email' => 'admin1@koedu.test', 'role' => UserRole::Admin],
            ['name' => 'Admin CS 2', 'email' => 'admin2@koedu.test', 'role' => UserRole::Admin],
        ];

        foreach ($staff as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data + ['password' => $password, 'email_verified_at' => now(), 'is_active' => true],
            );
        }
    }
}
