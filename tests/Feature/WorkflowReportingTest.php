<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\CompletenessService;
use App\Services\EntryService;
use App\Services\ReportingPeriodService;
use App\Services\ReportingService;
use App\Services\TargetService;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkflowReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_and_review_create_in_app_notifications(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', [
            'value_state' => 'reported',
            'numeric_value' => 20,
            'notes' => 'Monthly result',
        ], [], true);

        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->status);
        $this->assertSame(1, $reviewer->notifications()->count());
        $this->assertSame('approval_required', $reviewer->notifications()->first()->data['kind']);

        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', 'Checked');
        $this->assertSame(WorkflowStatus::PendingMneHead, $entry->fresh()->status);
        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', 'Final check complete');

        $this->assertSame(2, $submitter->notifications()->count());
        $this->assertDatabaseHas('indicator_entries', ['id' => $entry->id, 'status' => WorkflowStatus::FinalApproved]);
    }

    public function test_monthly_result_requires_a_target(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment();
        $assignment->targets()->whereDate('period', '2026-10-01')->update(['active' => false]);
        $this->actingAs($submitter)
            ->get(route('entries.edit', ['assignment' => $assignment, 'period' => '2026-10']))
            ->assertStatus(422);
        $this->actingAs($submitter)
            ->get(route('entries.index', ['period_type' => 'monthly', 'anchor' => '2026-10']))
            ->assertOk()
            ->assertDontSee($assignment->indicator->name);
    }

    public function test_monthly_result_uses_the_target_for_the_same_month(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment();
        app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-15',
            'value' => 30,
        ]);

        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-08-01', ['value_state' => 'reported', 'numeric_value' => 30], [], true);

        $this->assertSame('2026-08-01', $entry->period->toDateString());
        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->status);
        $this->actingAs($submitter)
            ->get(route('entries.edit', ['assignment' => $assignment, 'period' => '2026-08', 'period_type' => 'monthly']))
            ->assertOk()
            ->assertSee('Target:');
    }

    public function test_report_does_not_compare_results_with_only_some_period_targets(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment();
        foreach (['2026-09-01', '2026-10-01'] as $period) {
            $target = app(TargetService::class)->save([
                'indicator_assignment_id' => $assignment->id,
                'period' => $period,
                'period_type' => 'monthly',
                'target_type' => 'numeric',
                'value' => 20,
                'active' => true,
            ]);
            $entry = app(EntryService::class)->save($submitter, $assignment, $period, ['value_state' => 'reported', 'numeric_value' => 20], [], true);
            app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);
            app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);
        }

        $target->update(['active' => false]);
        $row = app(ReportingService::class)->report($reviewer, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'))->first();

        $this->assertSame(20.0, $row['achievement']);
        $this->assertSame(20.0, $row['target']);
        $this->assertSame('20', $row['target_display']);
    }

    public function test_all_projects_report_does_not_compare_with_only_one_project_target(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment();
        $secondCentre = Centre::create(['code' => 'C2', 'name' => 'Second centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $assignment->indicator->project->centres()->attach($secondCentre);
        foreach ([$submitter, $reviewer, $mneHead] as $user) {
            $user->centres()->attach($secondCentre);
        }
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $assignment->indicator_definition_id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);
        app(TargetService::class)->save([
            'indicator_assignment_id' => $secondAssignment->id,
            'period' => '2026-09-01',
            'value' => 20,
        ]);
        app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-09-01',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);

        foreach ([$assignment, $secondAssignment] as $projectAssignment) {
            $entry = app(EntryService::class)->save($submitter, $projectAssignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 20], [], true);
            app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);
            app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);
        }

        $row = app(ReportingService::class)->consolidatedReport($reviewer, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))->first();

        $this->assertSame(40.0, $row['achievement']);
        $this->assertSame(40.0, $row['target']);
    }

    public function test_completeness_requires_every_due_indicator(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment();
        $secondIndicator = IndicatorDefinition::create([
            'project_id' => $assignment->indicator->project_id,
            'code' => 'TEST-2',
            'name' => 'Second indicator',
            'description' => 'Second reporting obligation',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'active' => true,
        ]);
        IndicatorAssignment::create(['indicator_definition_id' => $secondIndicator->id, 'centre_id' => $assignment->centre_id, 'required' => true]);
        app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 12], [], false);

        $summary = app(CompletenessService::class)->summary($submitter, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

        $this->assertSame(1, $summary['expected']);
        $this->assertSame(1, $summary['reported']);
        $this->assertSame(0, $summary['missing']);
        $this->assertSame(100.0, $summary['rate']);
    }

    public function test_partial_custom_period_excludes_incomplete_months_and_warns_user(): void
    {
        $request = Request::create('/reports', 'GET', [
            'period_type' => 'custom',
            'start' => '2026-07-15',
            'end' => '2026-09-15',
        ]);

        $period = app(ReportingPeriodService::class)->fromRequest($request);

        $this->assertSame('2026-08-01', $period['data_start']->toDateString());
        $this->assertSame('2026-08-31', $period['data_end']->toDateString());
        $this->assertNotNull($period['warning']);
    }

    public function test_phase_four_pages_are_available_with_scoped_data(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment();
        app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 20], [], false);

        $this->actingAs($reviewer)->get('/')->assertOk();
        $this->actingAs($reviewer)->get(route('programmes.show', $assignment->indicator->project_id))->assertOk();
        $this->actingAs($reviewer)->get(route('projects.show', $assignment->centre_id))->assertOk();
        $this->actingAs($reviewer)->get(route('kpis.show', [$assignment->indicator->project_id, $assignment->indicator_definition_id]))->assertOk();
        $this->actingAs($reviewer)->get(route('reports.index'))->assertOk();
        $this->actingAs($reviewer)->get(route('reports.export'))->assertOk();
        $this->actingAs($reviewer)->get(route('reports.excel'))->assertOk();
        $this->actingAs($reviewer)->get('/data-quality')->assertNotFound();
        $this->actingAs($reviewer)->get(route('notifications.index'))->assertOk();
    }

    public function test_project_page_shows_every_indicator_and_monthly_fill_state(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment();
        $project = $assignment->indicator->project;
        $centre = $assignment->centre;
        app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 20,
        ], [], false);

        $targetedIndicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'TEST-TARGETED-EMPTY',
            'name' => 'Participants completing support',
            'description' => 'Participants completing support during the month.',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'effective_date' => '2026-04-01',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'target_tracking' => true,
            'active' => true,
        ]);
        $targetedAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $targetedIndicator->id,
            'centre_id' => $centre->id,
            'required' => true,
        ]);
        app(TargetService::class)->save([
            'indicator_assignment_id' => $targetedAssignment->id,
            'period' => '2026-10-01',
            'value' => 25,
        ]);

        $untargetedIndicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'TEST-NO-TARGET',
            'name' => 'Attendance rate',
            'description' => 'Monthly attendance rate.',
            'category' => 'Outcome',
            'unit' => '%',
            'data_type' => 'percentage',
            'frequency' => 'monthly',
            'effective_date' => '2026-04-01',
            'time_aggregation' => 'AVERAGE',
            'centre_aggregation' => 'AVERAGE',
            'target_tracking' => true,
            'active' => true,
        ]);
        $untargetedAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $untargetedIndicator->id,
            'centre_id' => $centre->id,
            'required' => true,
        ]);

        $response = $this->actingAs($submitter)->get(route('projects.show', [
            'centre' => $centre,
            'project' => $project->id,
            'anchor' => '2026-10',
        ]));

        $response
            ->assertOk()
            ->assertSee('Project type')
            ->assertSee('Assigned staff')
            ->assertSee('1 of 2 indicators filled')
            ->assertSee($assignment->indicator->name)
            ->assertSee($targetedIndicator->name)
            ->assertSee($untargetedIndicator->name)
            ->assertSee('Draft')
            ->assertSee('Not filled')
            ->assertSee('No target set')
            ->assertSee('Open')
            ->assertDontSee('<select id="project"', false)
            ->assertDontSee('Region')
            ->assertDontSee('Assigned programmes')
            ->assertDontSee('No records are available for this period.')
            ->assertSee(route('entries.edit', [
                'assignment' => $assignment,
                'period' => '2026-10',
                'project' => $project->id,
                'centre' => $centre->id,
            ]))
            ->assertSee(route('entries.edit', [
                'assignment' => $targetedAssignment,
                'period' => '2026-10',
                'project' => $project->id,
                'centre' => $centre->id,
            ]))
            ->assertDontSee(route('entries.edit', [
                'assignment' => $untargetedAssignment,
                'period' => '2026-10',
                'project' => $project->id,
                'centre' => $centre->id,
            ]));
    }

    public function test_organization_dashboard_shows_current_navigation_and_recent_activity_without_period_reporting(): void
    {
        [$assignment, , $reviewer] = $this->configuredAssignment();

        $this->actingAs($reviewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Active programmes')
            ->assertSee('Active projects')
            ->assertSee('Pending approvals')
            ->assertSee(route('programmes.index'), false)
            ->assertSee(route('projects.index'), false)
            ->assertSee(route('approvals.index'), false)
            ->assertSee(route('approvals.index', ['tab' => 'approved']), false)
            ->assertSee('Recent submissions')
            ->assertSee('Recently approved')
            ->assertDontSee('name="period_type"', false)
            ->assertDontSee('Project reporting completion')
            ->assertDontSee('Approval status')
            ->assertDontSee('Key approved outcomes');
    }

    public function test_programme_page_is_a_directory_without_reporting_or_evidence_sections(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 18, 'variance_explanation' => 'Attendance was affected by school closures.'], [], true);
        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);
        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);

        $this->actingAs($reviewer)
            ->get(route('programmes.show', [$assignment->indicator->project, 'section' => 'overview']))
            ->assertOk()
            ->assertDontSee('name="period_type"', false)
            ->assertDontSee('name="anchor"', false)
            ->assertSee('Assigned projects')
            ->assertSee('Indicators')
            ->assertSee('Activities')
            ->assertSee($assignment->indicator->name)
            ->assertSee('Code: '.$assignment->indicator->code)
            ->assertSee('Unit: sessions')
            ->assertSee('Project level')
            ->assertDontSee('Enter data')
            ->assertDontSee('Actual:')
            ->assertDontSee('Target:')
            ->assertDontSee('Reporting completion')
            ->assertDontSee('Evidence files');
    }

    public function test_reports_use_project_and_centre_filters_with_project_specific_centre_options(): void
    {
        [$assignment, , $reviewer] = $this->configuredAssignment();
        $otherProject = Project::create([
            'programme_id' => $assignment->indicator->project->programme_id,
            'code' => 'OTHER',
            'name' => 'Other project',
            'description' => 'Another project',
        ]);
        $otherCentre = Centre::create([
            'code' => 'C2',
            'name' => 'Other project centre',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $otherProject->centres()->attach($otherCentre);
        $reviewer->projects()->attach($otherProject);
        $reviewer->centres()->attach($otherCentre);

        $this->actingAs($reviewer)
            ->get(route('reports.index', ['project' => $assignment->indicator->project_id, 'anchor' => '2026-09']))
            ->assertOk()
            ->assertSee($assignment->centre->name)
            ->assertDontSee($otherCentre->name)
            ->assertSee('name="indicator"', false)
            ->assertSee('name="category"', false)
            ->assertSee('All programme projects');

        $this->actingAs($reviewer)
            ->get(route('reports.index', ['project' => $assignment->indicator->project_id, 'centre' => $otherCentre->id]))
            ->assertStatus(422);

        $this->actingAs($reviewer)
            ->get(route('reports.export', ['project' => $assignment->indicator->project_id, 'centre' => $otherCentre->id]))
            ->assertStatus(422);
    }

    public function test_project_centre_dependencies_are_applied_to_data_entry_and_kpi_pages(): void
    {
        [$assignment, , $reviewer] = $this->configuredAssignment();
        $otherProject = Project::create([
            'programme_id' => $assignment->indicator->project->programme_id,
            'code' => 'OTHER',
            'name' => 'Other project',
            'description' => 'Another project',
        ]);
        $otherCentre = Centre::create([
            'code' => 'C2',
            'name' => 'Other project centre',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $otherProject->centres()->attach($otherCentre);
        $reviewer->projects()->attach($otherProject);
        $reviewer->centres()->attach($otherCentre);
        $otherIndicator = IndicatorDefinition::create([
            'project_id' => $otherProject->id,
            'code' => 'OTHER-1',
            'name' => 'Other project indicator',
            'description' => 'Only belongs to the other project',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'active' => true,
        ]);
        IndicatorAssignment::create([
            'indicator_definition_id' => $otherIndicator->id,
            'centre_id' => $otherCentre->id,
            'required' => true,
        ]);

        $this->actingAs($reviewer)
            ->get(route('entries.index', ['project' => $assignment->indicator->project_id, 'lock_project' => 1]))
            ->assertOk()
            ->assertSee($assignment->centre->name)
            ->assertDontSee($otherCentre->name)
            ->assertDontSee($otherIndicator->name)
            ->assertDontSee('<select id="project"', false);

        $restrictedCentre = Centre::create([
            'code' => 'C3',
            'name' => 'Restricted project centre',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $assignment->indicator->project->centres()->attach($restrictedCentre);
        IndicatorAssignment::create([
            'indicator_definition_id' => $assignment->indicator_definition_id,
            'centre_id' => $restrictedCentre->id,
            'required' => true,
        ]);

        $this->actingAs($reviewer)
            ->get(route('kpis.show', [$assignment->indicator->project, $assignment->indicator]))
            ->assertOk()
            ->assertSee($assignment->centre->name)
            ->assertDontSee($restrictedCentre->name);
    }

    public function test_data_entry_orders_unfilled_records_first_and_supports_donor_and_indicator_filters(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment();
        $assignment->centre->update(['source_donor_name' => 'First Donor']);
        app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 20,
        ], [], false);

        $secondCentre = Centre::create([
            'code' => 'C2',
            'name' => 'Second donor project',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
            'source_donor_name' => 'Second Donor',
        ]);
        $assignment->indicator->project->centres()->attach($secondCentre);
        $submitter->centres()->attach($secondCentre);
        $secondIndicator = IndicatorDefinition::create([
            'project_id' => $assignment->indicator->project_id,
            'code' => 'SECOND-ENTRY-INDICATOR',
            'name' => 'Unfilled second indicator',
            'description' => 'A second reporting obligation.',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'active' => true,
        ]);
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $secondIndicator->id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);
        app(TargetService::class)->save([
            'indicator_assignment_id' => $secondAssignment->id,
            'period' => '2026-10-01',
            'value' => 25,
        ]);

        $this->actingAs($submitter)
            ->get(route('entries.index', ['anchor' => '2026-10']))
            ->assertOk()
            ->assertSeeInOrder([$secondIndicator->name, $assignment->indicator->name]);

        $this->actingAs($submitter)
            ->get(route('entries.index', [
                'anchor' => '2026-10',
                'donor' => 'Second Donor',
                'search' => 'SECOND-ENTRY',
            ]))
            ->assertOk()
            ->assertSee('Second Donor')
            ->assertSee($secondIndicator->name)
            ->assertDontSee($assignment->indicator->name)
            ->assertSee('0 <span class="text-sm font-medium text-slate-400">of 1</span>', false)
            ->assertSee('Records still missing · click to filter')
            ->assertSee('entry_status=unfilled', false)
            ->assertSee('x-on:input.debounce.500ms', false)
            ->assertDontSee('Reporting completion')
            ->assertDontSee('Continue entry')
            ->assertDontSee('Enter value')
            ->assertDontSee('View entry')
            ->assertDontSee('>Search</button>', false);

        $this->actingAs($submitter)
            ->get(route('entries.index', ['anchor' => '2026-10', 'entry_status' => 'unfilled']))
            ->assertOk()
            ->assertSee($secondIndicator->name)
            ->assertDontSee($assignment->indicator->name);

        $this->actingAs($submitter)
            ->get(route('entries.index', ['anchor' => '2026-10', 'entry_status' => 'entered']))
            ->assertOk()
            ->assertSee($assignment->indicator->name)
            ->assertDontSee($secondIndicator->name);

        $this->actingAs($submitter)
            ->post(route('entries.store', $secondAssignment), [
                'period' => '2026-10-01',
                'numeric_value' => 25,
                'action' => 'submit',
                'entry_status' => 'unfilled',
            ])
            ->assertRedirect(route('entries.index', ['period' => '2026-10', 'entry_status' => 'unfilled']));

        $this->actingAs($submitter)
            ->get(route('entries.index', ['anchor' => '2026-10', 'entry_status' => 'unfilled']))
            ->assertOk()
            ->assertDontSee($secondIndicator->name)
            ->assertDontSee($assignment->indicator->name);
    }

    public function test_empty_data_entry_month_links_to_other_months_with_targets(): void
    {
        [, $submitter] = $this->configuredAssignment();

        $this->actingAs($submitter)
            ->get(route('entries.index', ['anchor' => '2027-01']))
            ->assertOk()
            ->assertSee('No indicators have an active target for this month')
            ->assertSee('Targets are available in:')
            ->assertSee('Oct 2026');
    }

    public function test_data_entry_is_unavailable_before_an_indicator_reporting_start_month(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment(['effective_date' => '2026-10-01']);

        $this->actingAs($submitter)
            ->get(route('entries.index', [
                'project' => $assignment->indicator->project_id,
                'centre' => $assignment->centre_id,
                'period' => '2026-09',
            ]))
            ->assertOk()
            ->assertDontSee($assignment->indicator->name)
            ->assertSee('of 0');

        $this->actingAs($submitter)
            ->get(route('entries.edit', ['assignment' => $assignment, 'period' => '2026-09']))
            ->assertStatus(422);

        $this->actingAs($submitter)
            ->post(route('entries.store', $assignment), [
                'period' => '2026-09-01',
                'value_state' => 'reported',
                'numeric_value' => 10,
                'action' => 'save',
            ])
            ->assertSessionHasErrors('period');

        $this->assertDatabaseMissing('indicator_entries', [
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-09-01',
        ]);

        $this->actingAs($submitter)
            ->get(route('entries.index', [
                'project' => $assignment->indicator->project_id,
                'centre' => $assignment->centre_id,
                'period' => '2026-10',
            ]))
            ->assertOk()
            ->assertSee($assignment->indicator->name);
    }

    public function test_programme_page_groups_all_relevant_indicators_by_type(): void
    {
        [$assignment, , $reviewer] = $this->configuredAssignment();

        $this->actingAs($reviewer)
            ->get(route('programmes.show', [$assignment->indicator->project, 'section' => 'overview']))
            ->assertOk()
            ->assertSee('Assigned projects')
            ->assertSee('Activities')
            ->assertSee($assignment->indicator->name)
            ->assertDontSee('Overview')
            ->assertDontSee('Evidence files');
    }

    public function test_programme_page_shows_indicators_for_all_accessible_assigned_projects(): void
    {
        [$assignment, , $reviewer] = $this->configuredAssignment();
        $project = $assignment->indicator->project;
        $secondCentre = Centre::create([
            'code' => 'C2',
            'name' => 'Second project centre',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $project->centres()->attach($secondCentre);
        $reviewer->centres()->attach($secondCentre);
        $secondIndicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'SECOND-ACTIVITY',
            'name' => 'Second centre only activity',
            'description' => 'This indicator is assigned only to the second centre.',
            'category' => 'Activity',
            'unit' => 'sessions',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'active' => true,
        ]);
        IndicatorAssignment::create([
            'indicator_definition_id' => $secondIndicator->id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);

        $this->actingAs($reviewer)
            ->get(route('programmes.show', [$project, 'section' => 'activities', 'centre' => $assignment->centre_id]))
            ->assertOk()
            ->assertSee($assignment->indicator->name)
            ->assertSee($secondIndicator->name)
            ->assertSee($assignment->centre->name)
            ->assertSee($secondCentre->name)
            ->assertDontSee('name="centre"', false);
    }

    public function test_portal_header_uses_a_bell_and_remains_sticky(): void
    {
        [, , $reviewer] = $this->configuredAssignment();

        $this->actingAs($reviewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Notifications"', false)
            ->assertSee('<details class="group relative">', false)
            ->assertSee('<summary aria-label="Open account menu"', false)
            ->assertSee('sticky top-0', false);
    }

    public function test_private_evidence_preview_is_limited_to_authorized_staff(): void
    {
        Storage::fake('local');
        [$assignment, $submitter] = $this->configuredAssignment();
        $file = UploadedFile::fake()->image('proof.jpg');
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', [
            'value_state' => 'reported',
            'numeric_value' => 18,
        ], [$file], false);
        $evidence = $entry->currentRevision->evidence->first();
        $outsider = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);

        $this->actingAs($submitter)->get(route('evidence.preview', $evidence))->assertOk();
        $this->actingAs($submitter)
            ->get(route('programmes.show', [$assignment->indicator->project, 'section' => 'evidence']))
            ->assertOk()
            ->assertDontSee('proof.jpg');
        $this->actingAs($outsider)->get(route('evidence.preview', $evidence))->assertForbidden();
    }

    public function test_project_consolidation_uses_the_configured_centre_aggregation(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment([
            'time_aggregation' => 'LATEST SNAPSHOT',
            'centre_aggregation' => 'SUM',
        ]);
        $secondCentre = Centre::create(['code' => 'C2', 'name' => 'Second centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $assignment->indicator->project->centres()->attach($secondCentre);
        $submitter->centres()->attach($secondCentre);
        $reviewer->centres()->attach($secondCentre);
        $mneHead->centres()->attach($secondCentre);
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $assignment->indicator_definition_id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);
        app(TargetService::class)->save(['indicator_assignment_id' => $secondAssignment->id, 'period' => '2026-09-01', 'value' => 20]);

        foreach ([[$assignment, 35], [$secondAssignment, 20]] as [$centreAssignment, $value]) {
            $entry = app(EntryService::class)->save($submitter, $centreAssignment, '2026-09-01', [
                'value_state' => 'reported',
                'numeric_value' => $value,
                'variance_explanation' => 'The project result differed from its monthly target.',
            ], [], true);
            app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);
            app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);
        }

        $rows = app(ReportingService::class)->consolidatedReport(
            $reviewer,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
            $assignment->indicator->project_id,
        );

        $this->assertSame(55.0, $rows->first()['achievement']);
        $this->assertSame(2, $rows->first()['centre_count']);

        $this->actingAs($reviewer)
            ->get(route('reports.index', ['anchor' => '2026-09', 'mode' => 'consolidated']))
            ->assertOk()
            ->assertSee('All projects (2)')
            ->assertSee('SUM across projects')
            ->assertSee('55.00 sessions')
            ->assertDontSee('35.00 sessions');

        $this->actingAs($reviewer)
            ->get(route('reports.index', ['centre' => $assignment->centre_id, 'anchor' => '2026-09']))
            ->assertOk()
            ->assertSee('35.00 sessions')
            ->assertDontSee('55.00 sessions');

        $export = $this->actingAs($reviewer)->get(route('reports.export', ['anchor' => '2026-09', 'mode' => 'consolidated']));
        $export->assertOk();
        $this->assertStringContainsString('All projects (2)', $export->streamedContent());

        $this->actingAs($reviewer)
            ->get(route('programmes.show', [$assignment->indicator->project, 'section' => 'activities', 'anchor' => '2026-09']))
            ->assertOk()
            ->assertSee($assignment->indicator->name)
            ->assertDontSee('Actual: 55.00 sessions')
            ->assertDontSee('SUM across projects');
    }

    public function test_status_counts_are_combined_across_projects(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment([
            'data_type' => 'status',
            'unit' => 'status',
            'options' => ['Open', 'Closed'],
            'time_aggregation' => 'STATUS',
            'centre_aggregation' => 'STATUS',
            'target_tracking' => true,
            'direction' => 'status',
        ]);
        $secondCentre = Centre::create(['code' => 'C2', 'name' => 'Second centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $assignment->indicator->project->centres()->attach($secondCentre);
        $submitter->centres()->attach($secondCentre);
        $reviewer->centres()->attach($secondCentre);
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $assignment->indicator_definition_id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);
        foreach ([$assignment, $secondAssignment] as $statusAssignment) {
            app(TargetService::class)->save([
                'indicator_assignment_id' => $statusAssignment->id,
                'period' => '2026-09-01',
                'status_value' => 'Open',
            ]);
        }

        foreach ([[$assignment, 'Open'], [$secondAssignment, 'Closed']] as [$centreAssignment, $status]) {
            $entry = app(EntryService::class)->save($submitter, $centreAssignment, '2026-09-01', [
                'value_state' => 'reported',
                'text_value' => $status,
                'variance_explanation' => $status === 'Open' ? null : 'The project closed during the month.',
            ], [], true);
            app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);
            app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);
        }

        $rows = app(ReportingService::class)->consolidatedReport($reviewer, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

        $this->assertEquals(['Open' => 1, 'Closed' => 1], $rows->first()['achievement']);
        $this->actingAs($reviewer)
            ->get(route('programmes.show', [$assignment->indicator->project, 'section' => 'activities', 'anchor' => '2026-09']))
            ->assertOk()
            ->assertSee($assignment->indicator->name)
            ->assertDontSee('Open: 1')
            ->assertDontSee('Closed: 1');
    }

    public function test_editing_an_approved_result_preserves_the_official_revision_until_reapproval(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 10, 'variance_explanation' => 'Initial reported result.'], [], true);
        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);
        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);

        $entry = app(EntryService::class)->requestAmendment($submitter, $entry->fresh(), 'Correct a transcription mistake.');
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 12, 'variance_explanation' => 'Corrected reported result.'], [], true);
        $officialRows = app(ReportingService::class)->report($reviewer, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->status);
        $this->assertSame(3, $entry->revisions()->count());
        $this->assertEquals(10, $entry->approvedRevision->numeric_value);
        $this->assertSame(10.0, $officialRows->first()['achievement']);

        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', 'Updated evidence checked.');
        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', 'Final amendment check.');

        $updatedRows = app(ReportingService::class)->report($reviewer, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        $this->assertSame(12.0, $updatedRows->first()['achievement']);
    }

    public function test_approval_does_not_require_a_second_evidence_confirmation(): void
    {
        [$assignment, $submitter, $reviewer, $mneHead] = $this->configuredAssignment(['evidence_review_required' => true]);
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 20], [], true);

        $this->actingAs($reviewer)->post(route('approvals.review', $entry), ['action' => 'APPROVED'])->assertSessionHasNoErrors();
        $this->assertSame(WorkflowStatus::PendingMneHead, $entry->fresh()->status);

        $this->actingAs($mneHead)->post(route('approvals.review', $entry), ['action' => 'APPROVED'])->assertSessionHasNoErrors();
        $this->assertSame(WorkflowStatus::FinalApproved, $entry->fresh()->status);
    }

    public function test_required_evidence_is_still_checked_when_submitting(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment(['evidence_required' => true, 'min_files' => 1]);

        try {
            app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 20], [], true);
            $this->fail('Submission without required evidence should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }

        Storage::fake((string) config('impact.evidence_disk', 'local'));
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 20], [UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf')], true);

        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->status);
        $this->assertCount(1, $entry->currentRevision->evidence);
    }

    public function test_deadline_command_notifies_staff_with_missing_obligations_once(): void
    {
        [, $submitter] = $this->configuredAssignment();
        CarbonImmutable::setTestNow('2026-11-03 09:00:00');

        try {
            $this->artisan('impact:send-deadline-reminders')->assertSuccessful();
            $this->artisan('impact:send-deadline-reminders')->assertSuccessful();

            $this->assertSame(1, $submitter->notifications()->where('data->kind', 'deadline_2026_10')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array{IndicatorAssignment, User, User, User} */
    /** @param array<string, mixed> $indicatorOverrides */
    private function configuredAssignment(array $indicatorOverrides = []): array
    {
        $submitter = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $reviewer = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD', 'active' => true]);
        $mneHead = User::factory()->create(['role' => 'M&E HEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Test programme', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'TEST', 'name' => 'Test project', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'C1', 'name' => 'Test centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        foreach ([$submitter, $reviewer, $mneHead] as $user) {
            $user->projects()->attach($project);
            $user->centres()->attach($centre);
        }
        $indicator = IndicatorDefinition::create(array_merge([
            'project_id' => $project->id,
            'code' => 'TEST-1',
            'name' => 'Sessions',
            'description' => 'Sessions delivered',
            'category' => 'Activity',
            'unit' => 'sessions',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'min_value' => 0,
            'evidence_required' => false,
            'approval_required' => true,
            'active' => true,
        ], $indicatorOverrides));
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);
        foreach (range(1, 12) as $month) {
            $period = CarbonImmutable::create(2026, $month, 1);
            IndicatorTarget::create([
                'indicator_assignment_id' => $assignment->id,
                'period' => $period,
                'period_end' => $period->endOfMonth(),
                'period_type' => 'monthly',
                'target_type' => 'numeric',
                'value' => 20,
                'active' => true,
            ]);
        }

        return [$assignment->load(['indicator.project', 'centre']), $submitter, $reviewer, $mneHead];
    }
}
