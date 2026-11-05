<?php

use App\Models\IndicatorDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->concepts() as $codeSuffix => $concept) {
            IndicatorDefinition::query()
                ->where('is_demo', true)
                ->where('code', 'like', '%-'.$codeSuffix)
                ->get()
                ->each(function (IndicatorDefinition $indicator) use ($concept): void {
                    $indicator->name = $concept['name'];
                    $indicator->unit = $concept['unit'];
                    $indicator->save();
                });
        }
    }

    public function down(): void
    {
        $units = [
            'CORE-01' => 'people',
            'CORE-02' => 'average',
            'CORE-03' => '%',
            'CORE-04' => '%',
            'CORE-05' => '%',
            'CORE-06' => 'people',
            'CORE-07' => 'average',
        ];

        foreach ($units as $codeSuffix => $unit) {
            DB::table('indicator_definitions')
                ->where('is_demo', true)
                ->where('code', 'like', '%-'.$codeSuffix)
                ->update(['name' => 'Monthly programme participation', 'unit' => $unit]);
        }
    }

    /** @return array<string, array{name: string, unit: string}> */
    private function concepts(): array
    {
        return [
            'CORE-01' => ['name' => 'Learning sessions delivered', 'unit' => 'sessions'],
            'CORE-02' => ['name' => 'Average days absent per learner', 'unit' => 'days'],
            'CORE-03' => ['name' => 'Learners attending at least 80% of sessions', 'unit' => '%'],
            'CORE-04' => ['name' => 'Residents achieving individual care-plan goals', 'unit' => '%'],
            'CORE-05' => ['name' => 'Participants achieving employment outcomes', 'unit' => '%'],
            'CORE-06' => ['name' => 'Distinct community outreach locations visited', 'unit' => 'locations'],
            'CORE-07' => ['name' => 'Average delivery cost per learner', 'unit' => '₹ per learner'],
        ];
    }
};
