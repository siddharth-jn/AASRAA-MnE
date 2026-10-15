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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_and_review_create_in_app_notifications(): void
    {
        [$assignment, $submitter, $reviewer] = $this->configuredAssignment();

        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', [
            'value_state' => 'reported',
            'numeric_value' => 18,
            'notes' => 'Monthly result',
        ], [], true);

        $this->assertSame('SUBMITTED', $entry->status);
        $this->assertSame(1, $reviewer->notifications()->count());
        $this->assertSame('approval_required', $reviewer->notifications()->first()->data['kind']);

        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', 'Checked');

        $this->assertSame(1, $submitter->notifications()->count());
        $this->assertSame('approved', $submitter->notifications()->first()->data['kind']);
        $this->assertDatabaseHas('indicator_entries', ['id' => $entry->id, 'status' => 'APPROVED']);
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

        $this->assertSame(2, $summary['expected']);
        $this->assertSame(1, $summary['reported']);
        $this->assertSame(1, $summary['missing']);
        $this->assertSame(50.0, $summary['rate']);
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
        [$assignment, $submitter, $reviewer] = $this->configuredAssignment();
        app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 20], [], false);

        $this->actingAs($reviewer)->get('/')->assertOk();
        $this->actingAs($reviewer)->get(route('projects.show', $assignment->indicator->project_id))->assertOk();
        $this->actingAs($reviewer)->get(route('centres.show', $assignment->centre_id))->assertOk();
        $this->actingAs($reviewer)->get(route('kpis.show', [$assignment->indicator->project_id, $assignment->indicator_definition_id]))->assertOk();
        $this->actingAs($reviewer)->get(route('reports.index'))->assertOk();
        $this->actingAs($reviewer)->get(route('reports.export'))->assertOk();
        $this->actingAs($reviewer)->get(route('reports.excel'))->assertOk();
        $this->actingAs($reviewer)->get(route('data-quality.index'))->assertOk();
        $this->actingAs($reviewer)->get(route('notifications.index'))->assertOk();
    }

    public function test_organization_dashboard_shows_current_navigation_and_recent_activity_without_period_reporting(): void
    {
        [$assignment, , $reviewer] = $this->configuredAssignment();

        $this->actingAs($reviewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Active projects')
            ->assertSee('Active centres')
            ->assertSee('Pending approvals')
            ->assertSee(route('projects.index'), false)
            ->assertSee(route('centres.index'), false)
            ->assertSee(route('approvals.index'), false)
            ->assertSee('Recent submissions')
            ->assertSee('Recently approved')
            ->assertDontSee('name="period_type"', false)
            ->assertDontSee('Project reporting completion')
            ->assertDontSee('Approval status')
            ->assertDontSee('Key approved outcomes');
    }

    public function test_project_overview_has_no_period_form_and_activity_rows_show_target_and_actual(): void
    {
        [$assignment, $submitter, $reviewer] = $this->configuredAssignment();
        IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-09-01',
            'period_end' => '2026-09-30',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 18], [], true);
        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);

        $this->actingAs($reviewer)
            ->get(route('projects.show', [$assignment->indicator->project, 'section' => 'overview']))
            ->assertOk()
            ->assertDontSee('name="period_type"', false)
            ->assertSee('latest month with project data');

        $this->actingAs($reviewer)
            ->get(route('projects.show', [$assignment->indicator->project, 'section' => 'activities', 'period_type' => 'monthly', 'anchor' => '2026-09']))
            ->assertOk()
            ->assertSee('Activities')
            ->assertDontSee('Activitys')
            ->assertSee('Actual: 18.00 sessions')
            ->assertSee('Target: 20 sessions');
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
            ->assertDontSee('name="indicator"', false)
            ->assertDontSee('name="category"', false)
            ->assertSee('All project centres');

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

    public function test_data_entry_is_unavailable_before_an_indicator_reporting_start_month(): void
    {
        [$assignment, $submitter] = $this->configuredAssignment();
        $assignment->indicator->update(['effective_date' => '2026-10-01']);

        $this->actingAs($submitter)
            ->get(route('entries.index', [
                'project' => $assignment->indicator->project_id,
                'centre' => $assignment->centre_id,
                'period' => '2026-09',
            ]))
            ->assertOk()
            ->assertDontSee($assignment->indicator->name)
            ->assertSee('of 0 required');

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

    public function test_project_tabs_show_only_the_selected_section_content(): void
    {
        [$assignment, , $reviewer] = $this->configuredAssignment();

        $this->actingAs($reviewer)
            ->get(route('projects.show', [$assignment->indicator->project, 'section' => 'overview']))
            ->assertOk()
            ->assertSee('Reporting locations')
            ->assertDontSee('Activity indicators');

        $this->actingAs($reviewer)
            ->get(route('projects.show', [$assignment->indicator->project, 'section' => 'activities']))
            ->assertOk()
            ->assertSee('Activity indicators')
            ->assertSee($assignment->indicator->name)
            ->assertDontSee('Reporting locations');
    }

    public function test_project_centre_selection_shows_only_indicators_assigned_to_that_centre(): void
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
            ->get(route('projects.show', [$project, 'section' => 'activities', 'centre' => $assignment->centre_id]))
            ->assertOk()
            ->assertSee('Showing indicators assigned to '.$assignment->centre->name.'.')
            ->assertSee($assignment->indicator->name)
            ->assertDontSee($secondIndicator->name)
            ->assertSee('centre='.$assignment->centre_id, false);

        $this->actingAs($reviewer)
            ->get(route('projects.show', [$project, 'section' => 'overview']))
            ->assertOk()
            ->assertSee('View indicators for this centre');
    }

    public function test_portal_header_uses_a_bell_and_remains_sticky(): void
    {
        [, , $reviewer] = $this->configuredAssignment();

        $this->actingAs($reviewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Notifications"', false)
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
            ->get(route('projects.show', [$assignment->indicator->project, 'section' => 'evidence']))
            ->assertOk()
            ->assertSee('proof.jpg');
        $this->actingAs($outsider)->get(route('evidence.preview', $evidence))->assertForbidden();
    }

    public function test_project_consolidation_uses_the_configured_centre_aggregation(): void
    {
        [$assignment, $submitter, $reviewer] = $this->configuredAssignment();
        $assignment->indicator->update(['time_aggregation' => 'LATEST SNAPSHOT', 'centre_aggregation' => 'SUM']);
        $secondCentre = Centre::create(['code' => 'C2', 'name' => 'Second centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $assignment->indicator->project->centres()->attach($secondCentre);
        $submitter->centres()->attach($secondCentre);
        $reviewer->centres()->attach($secondCentre);
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $assignment->indicator_definition_id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);

        foreach ([[$assignment, 35], [$secondAssignment, 20]] as [$centreAssignment, $value]) {
            $entry = app(EntryService::class)->save($submitter, $centreAssignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => $value], [], true);
            app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);
        }

        $rows = app(ReportingService::class)->consolidatedReport(
            $reviewer,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
            $assignment->indicator->project_id,
        );

        $this->assertSame(55.0, $rows->first()['achievement']);
    }

    public function test_editing_an_approved_result_preserves_the_official_revision_until_reapproval(): void
    {
        [$assignment, $submitter, $reviewer] = $this->configuredAssignment();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 10], [], true);
        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', null);

        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 12], [], true);
        $officialRows = app(ReportingService::class)->report($reviewer, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

        $this->assertSame('SUBMITTED', $entry->status);
        $this->assertSame(2, $entry->revisions()->count());
        $this->assertEquals(10, $entry->approvedRevision->numeric_value);
        $this->assertSame(10.0, $officialRows->first()['achievement']);

        app(EntryService::class)->review($reviewer, $entry, 'APPROVED', 'Updated evidence checked.');

        $updatedRows = app(ReportingService::class)->report($reviewer, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        $this->assertSame(12.0, $updatedRows->first()['achievement']);
    }

    public function test_evidence_review_confirmation_is_required_before_approval(): void
    {
        [$assignment, $submitter, $reviewer] = $this->configuredAssignment();
        $assignment->indicator->update(['evidence_review_required' => true]);
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-09-01', ['value_state' => 'reported', 'numeric_value' => 18], [], true);

        $this->actingAs($reviewer)->post(route('approvals.review', $entry), ['action' => 'APPROVED'])->assertSessionHasErrors('evidence_reviewed');
        $this->assertSame('SUBMITTED', $entry->fresh()->status);

        $this->actingAs($reviewer)->post(route('approvals.review', $entry), [
            'action' => 'APPROVED',
            'evidence_reviewed' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $entry->fresh()->status);
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

    /** @return array{IndicatorAssignment, User, User} */
    private function configuredAssignment(): array
    {
        $submitter = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $reviewer = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Test programme', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'TEST', 'name' => 'Test project', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'C1', 'name' => 'Test centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        foreach ([$submitter, $reviewer] as $user) {
            $user->projects()->attach($project);
            $user->centres()->attach($centre);
        }
        $indicator = IndicatorDefinition::create([
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
        ]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);

        return [$assignment->load(['indicator.project', 'centre']), $submitter, $reviewer];
    }
}
