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
use App\Services\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_use_variance_formula_and_indicator_and_donor_filters(): void
    {
        $submitter = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $seniorLead = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD', 'active' => true]);
        $mneHead = User::factory()->create(['role' => 'M&E HEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'ORG-1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun', 'source_donor_name' => 'Example Donor']);
        $project->centres()->attach($centre);
        foreach ([$submitter, $seniorLead] as $user) {
            $user->projects()->attach($project);
            $user->centres()->attach($centre);
        }
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'OUTPUT',
            'name' => 'Learners reached',
            'description' => 'Test',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'approval_required' => true,
            'active' => true,
        ]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);
        IndicatorTarget::create(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'period_end' => '2026-10-31', 'period_type' => 'monthly', 'target_type' => 'numeric', 'value' => 100, 'active' => true]);
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 115,
            'variance_explanation' => 'Additional outreach increased participation.',
        ], [], true);
        app(EntryService::class)->review($seniorLead, $entry, 'APPROVED', null);
        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);

        $rows = app(ReportingService::class)->report($mneHead, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $project->id, null, $indicator->id, 'Output', 'Example Donor');

        $this->assertCount(1, $rows);
        $this->assertEquals(15.0, $rows->first()['achievement_percentage']);
        $this->assertSame('Additional outreach increased participation.', $rows->first()['variance_explanations']->first());

        $this->actingAs($mneHead)
            ->get(route('reports.index', ['project' => $project->id, 'indicator' => $indicator->id, 'donor' => 'Example Donor', 'anchor' => '2026-10']))
            ->assertOk()
            ->assertSee('Variance explanation')
            ->assertSee('Example Donor');

        $this->actingAs($mneHead)->get(route('programmes.index'))->assertOk()->assertSee('Activity')->assertSee('Output')->assertSee('Outcome');
    }
}
