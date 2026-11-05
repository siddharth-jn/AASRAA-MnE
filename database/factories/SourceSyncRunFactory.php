<?php

namespace Database\Factories;

use App\Models\SourceSyncRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceSyncRun>
 */
class SourceSyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => 'projects database',
            'snapshot_hash' => hash('sha256', fake()->uuid()),
            'status' => 'completed',
            'summary' => ['created' => 0, 'updated' => 0],
            'started_at' => now(),
            'completed_at' => now(),
            'is_demo' => false,
        ];
    }
}
