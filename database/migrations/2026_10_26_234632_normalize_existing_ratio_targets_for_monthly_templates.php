<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('indicator_targets')
            ->join('indicator_assignments', 'indicator_assignments.id', '=', 'indicator_targets.indicator_assignment_id')
            ->join('indicator_definitions', 'indicator_definitions.id', '=', 'indicator_assignments.indicator_definition_id')
            ->where(function ($query): void {
                $query->whereIn('indicator_definitions.data_type', ['ratio', 'calculated'])
                    ->orWhere(function ($percentageQuery): void {
                        $percentageQuery->where('indicator_definitions.data_type', 'percentage')
                            ->where('indicator_definitions.calculation_method', 'ratio_percentage');
                    });
            })
            ->whereNotNull('indicator_targets.value')
            ->where('indicator_targets.target_type', '!=', 'ratio')
            ->select(['indicator_targets.id', 'indicator_targets.value'])
            ->orderBy('indicator_targets.id')
            ->get()
            ->each(function (object $target): void {
                DB::table('indicator_targets')->where('id', $target->id)->update([
                    'target_type' => 'ratio',
                    'target_numerator' => $target->value,
                    'target_denominator' => 100,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
