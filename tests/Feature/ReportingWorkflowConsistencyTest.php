<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\EntryService;
use App\Services\IndicatorReplacementService;
use App\Services\ReportingService;
use App\Services\ScopeService;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingWorkflowConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_amendment_always_requires_approval_and_the_previous_revision_remains_official(): void
    {
        [$administrator, $viewer, $assignment] = $this->fixture();
        $service = app(EntryService::class);
        $entry = $service->save($administrator, $assignment, '2026-10-01', [
            'numeric_value' => 100,
            'value_state' => 'reported',
        ], [], true);
        $approvedRevisionId = $entry->approved_revision_id;

        $service->requestAmendment($administrator, $entry, 'Correct the verified attendance total.');
        $entry = $service->save($administrator, $assignment, '2026-10-01', [
            'numeric_value' => 120,
            'value_state' => 'reported',
            'variance_explanation' => 'The corrected register includes additional verified participants.',
        ], [], true);

        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->status);
        $this->assertSame($approvedRevisionId, $entry->approved_revision_id);
        $this->assertSame($approvedRevisionId, $entry->currentRevision->supersedes_revision_id);
        $this->assertTrue(app(ScopeService::class)->entries($viewer)->whereKey($entry)->exists());

        $row = app(ReportingService::class)->report(
            $viewer,
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-31'),
        )->first();
        $this->assertSame(100.0, $row['achievement']);

        $this->actingAs($administrator)
            ->get(route('approvals.index', ['tab' => 'approved']))
            ->assertOk()
            ->assertSee('Final approved')
            ->assertSee('newer Pending Senior Programme Lead amendment');
    }

    public function test_performance_classification_observes_boundaries_direction_and_zero_targets(): void
    {
        [$administrator, , $assignment] = $this->fixture();
        $service = app(EntryService::class);

        foreach ([
            ['period' => '2026-10-01', 'target' => 100, 'achievement' => 110, 'expected' => 'On track'],
            ['period' => '2026-11-01', 'target' => 100, 'achievement' => 111, 'expected' => 'Overachiever'],
            ['period' => '2026-12-01', 'target' => 100, 'achievement' => 89, 'expected' => 'Needs attention'],
            ['period' => '2027-01-01', 'target' => 0, 'achievement' => 3, 'expected' => 'Not assessed'],
        ] as $scenario) {
            $target = IndicatorTarget::query()
                ->where('indicator_assignment_id', $assignment->id)
                ->whereDate('period', $scenario['period'])
                ->first();
            if ($target) {
                $target->update([
                    'period_end' => CarbonImmutable::parse($scenario['period'])->endOfMonth(),
                    'period_type' => 'monthly',
                    'target_type' => 'numeric',
                    'value' => $scenario['target'],
                    'active' => true,
                ]);
            } else {
                IndicatorTarget::query()->create([
                    'indicator_assignment_id' => $assignment->id,
                    'period' => $scenario['period'],
                    'period_end' => CarbonImmutable::parse($scenario['period'])->endOfMonth(),
                    'period_type' => 'monthly',
                    'target_type' => 'numeric',
                    'value' => $scenario['target'],
                    'active' => true,
                ]);
            }
            $service->save($administrator, $assignment, $scenario['period'], [
                'numeric_value' => $scenario['achievement'],
                'value_state' => 'reported',
                'variance_explanation' => 'The result differs from the target.',
            ], [], true);

            $period = CarbonImmutable::parse($scenario['period']);
            $row = app(ReportingService::class)->report($administrator, $period, $period->endOfMonth())->first();
            $this->assertSame($scenario['expected'], $row['performance_status']);
        }

        $lowerIndicator = app(IndicatorReplacementService::class)->replace($assignment->indicator, '2027-02');
        $lowerIndicator->update(['direction' => 'lower']);
        $lowerAssignment = $lowerIndicator->assignments()->firstOrFail();
        IndicatorTarget::query()->create([
            'indicator_assignment_id' => $lowerAssignment->id,
            'period' => '2027-02-01',
            'period_end' => '2027-02-28',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 100,
            'active' => true,
        ]);
        $service->save($administrator, $lowerAssignment->fresh(['indicator.project', 'centre']), '2027-02-01', [
            'numeric_value' => 89,
            'value_state' => 'reported',
            'variance_explanation' => 'The lower result is favourable.',
        ], [], true);
        $february = CarbonImmutable::parse('2027-02-01');
        $row = app(ReportingService::class)->report($administrator, $february, $february->endOfMonth())->first();
        $this->assertSame('Overachiever', $row['performance_status']);

        $filtered = app(ReportingService::class)->filterByPerformance(collect([
            ['performance_status' => 'On track'],
            ['performance_status' => 'Needs attention'],
        ]), 'needs_attention');
        $this->assertCount(1, $filtered);
        $this->assertSame('Needs attention', $filtered->first()['performance_status']);
    }

    public function test_executive_overview_shows_monthly_exception_tabs_without_on_track_rows(): void
    {
        [$administrator, , $assignment] = $this->fixture();
        $service = app(EntryService::class);
        $service->save($administrator, $assignment, '2026-10-01', [
            'numeric_value' => 80,
            'value_state' => 'reported',
            'variance_explanation' => 'Attendance was below the monthly plan.',
        ], [], true);

        $overachieverIndicator = IndicatorDefinition::query()->create([
            'project_id' => $assignment->indicator->project_id,
            'code' => 'OUT-2',
            'name' => 'Learners completing support',
            'description' => 'Test',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'approval_required' => false,
            'active' => true,
        ]);
        $overachieverAssignment = IndicatorAssignment::query()->create([
            'indicator_definition_id' => $overachieverIndicator->id,
            'centre_id' => $assignment->centre_id,
            'required' => true,
        ]);
        IndicatorTarget::query()->create([
            'indicator_assignment_id' => $overachieverAssignment->id,
            'period' => '2026-10-01',
            'period_end' => '2026-10-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 100,
            'active' => true,
        ]);
        $service->save($administrator, $overachieverAssignment->load(['indicator.project', 'centre']), '2026-10-01', [
            'numeric_value' => 120,
            'value_state' => 'reported',
            'variance_explanation' => 'Additional learners completed the programme.',
        ], [], true);

        $this->actingAs($administrator)
            ->get(route('executive.dashboard', ['month' => '2026-10']))
            ->assertOk()
            ->assertSee('Needs attention · 1')
            ->assertSee('Overachievers · 1')
            ->assertSee($assignment->indicator->name)
            ->assertSee($overachieverIndicator->name)
            ->assertDontSee('On track');

        $this->actingAs($administrator)
            ->get(route('executive.dashboard', ['month' => '2026-10', 'performance' => 'needs_attention']))
            ->assertOk()
            ->assertSee($assignment->indicator->name)
            ->assertDontSee($overachieverIndicator->name);
    }

    /** @return array{User, User, IndicatorAssignment} */
    private function fixture(): array
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $viewer = User::factory()->create(['role' => 'VIEWER', 'active' => true]);
        $programme = Programme::query()->create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::query()->create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::query()->create(['code' => 'C1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $viewer->projects()->attach($project);
        $viewer->centres()->attach($centre);
        $indicator = IndicatorDefinition::query()->create([
            'project_id' => $project->id,
            'code' => 'OUT-1',
            'name' => 'Learners reached',
            'description' => 'Test',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'approval_required' => false,
            'active' => true,
        ]);
        $assignment = IndicatorAssignment::query()->create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);
        IndicatorTarget::query()->create(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'period_end' => '2026-10-31', 'period_type' => 'monthly', 'target_type' => 'numeric', 'value' => 100, 'active' => true]);

        return [$administrator, $viewer, $assignment->load(['indicator.project', 'centre'])];
    }
}
