<?php

namespace Database\Factories;

use App\Models\Centre;
use App\Models\CentreDonor;
use App\Models\Donor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CentreDonor>
 */
class CentreDonorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'centre_id' => Centre::query()->inRandomOrder()->value('id'),
            'donor_id' => Donor::factory(),
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => null,
            'is_primary' => true,
            'is_demo' => false,
        ];
    }
}
