<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Project::query()->where('is_demo', false)->whereHas('centres')->exists()) {
            $this->call(ShowcaseSeeder::class);
        }
    }
}
