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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IndicatorReportingPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_periods_follow_the_april_financial_year(): void
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

    public function test_entries_from_any_month_in_a_quarter_update_one_quarterly_record(): void
    {
        [$assignment, $user] = $this->configuredAssignment('quarterly', '2026-02-01');

        $first = app(EntryService::class)->save($user, $assignment, '2026-05-18', [
            'value_state' => 'reported',
            'numeric_value' => 12,
        ], [], false);
        $second = app(EntryService::class)->save($user, $assignment, '2026-06-22', [
            'value_state' => 'reported',
            'numeric_value' => 18,
        ], [], false);

        $this->assertTrue($first->is($second));
        $this->assertSame('2026-04-01', $second->period->toDateString());
        $this->assertSame(1, $assignment->entries()->count());
        $this->assertSame(2, $second->revisions()->count());
    }

    public function test_a_reporting_period_containing_the_start_month_is_available(): void
    {
        [$assignment, $user] = $this->configuredAssignment('quarterly', '2026-02-01');

        $entry = app(EntryService::class)->save($user, $assignment, '2026-03-01', [
            'value_state' => 'reported',
            'numeric_value' => 8,
        ], [], false);

        $this->assertSame('2026-01-01', $entry->period->toDateString());
    }

    public function test_half_yearly_and_annual_entries_use_one_canonical_period(): void
    {
        foreach ([
            ['half_yearly', '2026-11-15', '2026-10-01'],
            ['annual', '2027-02-10', '2026-04-01'],
        ] as [$frequency, $reportedDate, $expectedPeriod]) {
            [$assignment, $user] = $this->configuredAssignment($frequency, '2026-04-01');

            $entry = app(EntryService::class)->save($user, $assignment, $reportedDate, [
                'value_state' => 'reported',
                'numeric_value' => 10,
            ], [], false);

            $this->assertSame($expectedPeriod, $entry->period->toDateString());
        }
    }

    public function test_a_period_fully_before_the_reporting_start_is_rejected(): void
    {
        [$assignment, $user] = $this->configuredAssignment('quarterly', '2026-04-01');

        $this->expectException(ValidationException::class);

        app(EntryService::class)->save($user, $assignment, '2026-03-01', [
            'value_state' => 'reported',
            'numeric_value' => 8,
        ], [], false);
    }

    public function test_data_entry_filters_indicators_by_reporting_frequency(): void
    {
        [$quarterlyAssignment, $user] = $this->configuredAssignment('quarterly', '2026-02-01');
        $monthlyIndicator = IndicatorDefinition::create([
            'project_id' => $quarterlyAssignment->indicator->project_id,
            'code' => 'MONTHLY-1',
            'name' => 'Monthly sessions',
            'description' => 'A monthly indicator.',
            'category' => 'Activity',
            'unit' => 'sessions',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'effective_date' => '2026-02-01',
            'active' => true,
        ]);
        IndicatorAssignment::create([
            'indicator_definition_id' => $monthlyIndicator->id,
            'centre_id' => $quarterlyAssignment->centre_id,
            'required' => true,
        ]);

        $this->actingAs($user)
            ->get(route('entries.index', [
                'period_type' => 'quarterly',
                'anchor' => '2026-05',
            ]))
            ->assertOk()
            ->assertSee($quarterlyAssignment->indicator->name)
            ->assertDontSee($monthlyIndicator->name)
            ->assertSee('FY 2026–27 · Q1 (Apr–Jun 2026)')
            ->assertSee('One entry covers this entire reporting period');
    }

    /** @return array{IndicatorAssignment, User} */
    private function configuredAssignment(string $frequency, string $effectiveDate): array
    {
        $user = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $suffix = strtoupper($frequency);
        $programme = Programme::create(['name' => "Test programme {$suffix}", 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => "TEST-{$suffix}", 'name' => "Test project {$suffix}", 'description' => 'Test']);
        $centre = Centre::create(['code' => "C-{$suffix}", 'name' => "Test centre {$suffix}", 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $user->projects()->attach($project);
        $user->centres()->attach($centre);
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => "PERIOD-{$suffix}",
            'name' => "Period-aware indicator {$suffix}",
            'description' => 'Reported according to its configured frequency.',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => $frequency,
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'effective_date' => $effectiveDate,
            'active' => true,
        ]);
        $assignment = IndicatorAssignment::create([
            'indicator_definition_id' => $indicator->id,
            'centre_id' => $centre->id,
            'required' => true,
        ]);

        return [$assignment->load(['indicator.project', 'centre']), $user];
    }
}
