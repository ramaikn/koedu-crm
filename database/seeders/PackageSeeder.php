<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = require __DIR__.'/data/packages.php';

        DB::transaction(function () use ($packages) {
            foreach ($packages as $data) {
                $benefits = $data['benefits'];
                unset($data['benefits']);

                // updateOrCreate per kode, supaya seeder bisa dijalankan ulang tanpa duplikat.
                $package = Package::updateOrCreate(['code' => $data['code']], $data + ['is_active' => true]);

                $package->benefits()->delete();
                foreach ($benefits as $type => $quantity) {
                    $package->benefits()->create(['benefit_type' => $type, 'quantity' => $quantity]);
                }
            }
        });
    }
}
