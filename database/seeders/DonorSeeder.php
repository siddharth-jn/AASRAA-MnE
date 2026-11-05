<?php

namespace Database\Seeders;

use App\Models\Donor;
use Illuminate\Database\Seeder;

class DonorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Donor::query()->firstOrCreate(
            ['code' => 'TATA'],
            ['name' => 'TATA', 'active' => true, 'is_demo' => false],
        );
    }
}
