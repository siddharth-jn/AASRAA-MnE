<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
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
