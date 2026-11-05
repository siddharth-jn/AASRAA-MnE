<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\EntryService;
use App\Services\IndicatorReportingPeriodService;
use App\Services\TargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IndicatorReportingPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_periods_follow_the_april_financial_year(): void
    {
        $periods = app(IndicatorReportingPeriodService::class);
        [$quarterStart, $quarterEnd] = $periods->boundaries('2026-05-18', 'quarterly');
        [$halfYearStart, $halfYearEnd] = $periods->boundaries('2026-11-10', 'half_yearly');
        [$annualStart, $annualEnd] = $periods->boundaries('2027-02-01', 'annual');

        $this->assertSame('2026-04-01', $quarterStart->toDateString());
        $this->assertSame('2026-06-30', $quarterEnd->toDateString());
        $this->assertSame('2026-10-01', $halfYearStart->toDateString());
        $this->assertSame('2027-03-31', $halfYearEnd->toDateString());
        $this->assertSame('2026-04-01', $annualStart->toDateString());
        $this->assertSame('2027-03-31', $annualEnd->toDateString());
    }

    public function test_monthly_entries_remain_distinct_and_reports_can_aggregate_them(): void
    {
        [$assignment, $user] = $this->configuredAssignment('2026-02-01');
        foreach (['2026-05-01' => 12, '2026-06-01' => 18] as $period => $value) {
            app(TargetService::class)->save(['indicator_assignment_id' => $assignment->id, 'period' => $period, 'value' => $value]);
            app(EntryService::class)->save($user, $assignment, $period, ['value_state' => 'reported', 'numeric_value' => $value], [], false);
        }

        $this->assertSame(2, $assignment->entries()->count());
        $this->assertEqualsCanonicalizing(['2026-05-01', '2026-06-01'], $assignment->entries()->pluck('period')->map->toDateString()->all());
    }

    public function test_target_before_reporting_start_is_rejected(): void
    {
        [$assignment] = $this->configuredAssignment('2026-04-01');
        $this->expectException(ValidationException::class);

        app(TargetService::class)->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-03-01', 'value' => 8]);
    }

    /** @return array{IndicatorAssignment, User} */
    private function configuredAssignment(string $effectiveDate): array
    {
        $user = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Test programme', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'TEST', 'name' => 'Test project', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'C-1', 'name' => 'Test centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $user->projects()->attach($project);
        $user->centres()->attach($centre);
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id, 'code' => 'PERIOD-MONTHLY', 'name' => 'Monthly indicator',
            'description' => 'Reported monthly.', 'category' => 'Output', 'unit' => 'people', 'data_type' => 'integer',
            'frequency' => 'monthly', 'time_aggregation' => 'SUM', 'centre_aggregation' => 'SUM',
            'effective_date' => $effectiveDate, 'target_tracking' => true, 'active' => true,
        ]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);

        return [$assignment->load(['indicator.project', 'centre']), $user];
    }
}
