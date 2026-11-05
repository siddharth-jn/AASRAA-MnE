<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\CentreDonor;
use App\Models\Donor;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\TargetImportBatch;
use App\Models\User;
use App\Services\EntryService;
use App\Services\ReportingService;
use App\Services\TargetService;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TargetPlanningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_management_permissions_follow_the_operational_role_hierarchy(): void
    {
        [$assignment, $admin, $mneHead, $programmeLead, $seniorLead] = $this->fixture();
        $assistantLead = User::factory()->create(['role' => 'ASSISTANT PROGRAM LEAD', 'active' => true]);
        $assistantLead->projects()->attach($assignment->indicator->project);
        $assistantLead->centres()->attach($assignment->centre);
        $centreCoordinator = User::factory()->create(['role' => 'CENTRE COORDINATOR', 'active' => true]);
        $centreCoordinator->projects()->attach($assignment->indicator->project);
        $centreCoordinator->centres()->attach($assignment->centre);

        foreach ([$admin, $mneHead, $assistantLead, $programmeLead, $seniorLead] as $targetPlanner) {
            $this->actingAs($targetPlanner)->get(route('targets.index'))->assertOk()->assertSee('Targets');
            $this->actingAs($targetPlanner)->get(route('targets.template.all', ['financial_year' => 2026]))->assertOk();
        }
        $this->actingAs($centreCoordinator)->get(route('targets.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'VIEWER', 'active' => true]))
            ->get(route('targets.index'))
            ->assertForbidden();
        $this->actingAs($admin)->get(route('targets.index'))
            ->assertSee('Download Excel template')
            ->assertSee('Preview target changes')
            ->assertDontSee('Save monthly targets')
            ->assertDontSee('name="targets[', false);
        $this->assertFalse(Route::has('targets.update'));
        $this->assertTrue($assignment->exists);
    }

    public function test_programme_roles_can_manage_targets_only_for_their_assigned_projects(): void
    {
        [$assignedAssignment, $admin, , $programmeLead] = $this->fixture();
        $unassignedCentre = Centre::create(['code' => 'P-2', 'name' => 'Unassigned Project', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $assignedAssignment->indicator->project->centres()->attach($unassignedCentre);
        $unassignedAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $assignedAssignment->indicator_definition_id,
            'centre_id' => $unassignedCentre->id,
            'required' => true,
        ]);

        $response = $this->actingAs($programmeLead)->get(route('targets.template.all', ['financial_year' => 2026]));
        $path = storage_path('framework/testing/scoped-targets-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');

        $this->assertNotNull($this->findAssignmentRow($sheet, $assignedAssignment->id));
        $this->assertNull($this->findAssignmentRow($sheet, $unassignedAssignment->id));
        $spreadsheet->disconnectWorksheets();
        @unlink($path);

        $foreignBatch = TargetImportBatch::create([
            'user_id' => $admin->id,
            'project_id' => $assignedAssignment->indicator->project_id,
            'financial_year_start' => 2026,
            'filename' => 'admin-targets.xlsx',
            'path' => 'target-imports/admin-targets.xlsx',
            'fingerprint' => hash('sha256', 'admin-targets'),
            'status' => 'preview',
            'summary' => [],
        ]);

        $this->actingAs($programmeLead)->get(route('targets.imports.show', $foreignBatch))->assertForbidden();
        $this->actingAs($programmeLead)->post(route('targets.imports.confirm', $foreignBatch), ['confirmation' => '1'])->assertForbidden();
    }

    public function test_saved_targets_can_be_reviewed_by_period_and_indicator_search(): void
    {
        [$assignment, $admin] = $this->fixture();
        foreach (['2026-04-01' => 10, '2026-05-01' => 20, '2026-06-01' => 30] as $period => $value) {
            app(TargetService::class)->save([
                'indicator_assignment_id' => $assignment->id,
                'period' => $period,
                'value' => $value,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('targets.index', [
                'financial_year' => 2026,
                'programme' => $assignment->indicator->project_id,
                'target_period_type' => 'quarterly',
                'target_anchor' => '2026-04',
                'indicator_search' => 'MONTHLY-OUTPUT',
            ]))
            ->assertOk()
            ->assertSee('Saved targets')
            ->assertSee('FY 2026–27 · Q1 (Apr–Jun 2026)')
            ->assertSee($assignment->indicator->name)
            ->assertSee('60')
            ->assertSeeInOrder(['Apr', '10', 'May', '20', 'Jun', '30']);

        $this->actingAs($admin)
            ->get(route('targets.index', [
                'financial_year' => 2026,
                'programme' => $assignment->indicator->project_id,
                'target_period_type' => 'quarterly',
                'target_anchor' => '2026-04',
                'indicator_search' => 'indicator that does not exist',
            ]))
            ->assertOk()
            ->assertSee('No saved targets match these filters.');

        $this->actingAs($admin)
            ->get(route('targets.index', [
                'financial_year' => 2026,
                'programme' => $assignment->indicator->project_id,
                'target_period_type' => 'annual',
                'target_anchor' => '2026-04',
            ]))
            ->assertOk()
            ->assertSee('FY 2026–27 (Apr 2026–Mar 2027)')
            ->assertSee($assignment->indicator->name);
    }

    public function test_blank_target_hides_data_entry_but_zero_target_opens_it(): void
    {
        [$assignment, , , $programmeLead] = $this->fixture();

        $this->actingAs($programmeLead)
            ->get(route('entries.index', ['period_type' => 'monthly', 'anchor' => '2026-10']))
            ->assertOk()->assertDontSee($assignment->indicator->name);

        app(TargetService::class)->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'value' => 0]);

        $this->actingAs($programmeLead)
            ->get(route('entries.index', ['period_type' => 'monthly', 'anchor' => '2026-10']))
            ->assertOk()
            ->assertSee($assignment->indicator->name)
            ->assertSee('Open')
            ->assertSee('type="month"', false)
            ->assertDontSee('name="period_type"', false)
            ->assertDontSee('Reporting period');
    }

    public function test_target_change_requires_reentry_and_preserves_history(): void
    {
        [$assignment, , $mneHead, $programmeLead, $seniorLead] = $this->fixture();
        $targets = app(TargetService::class);
        $targets->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'value' => 100]);
        $entry = app(EntryService::class)->save($programmeLead, $assignment, '2026-10-01', ['numeric_value' => 100, 'value_state' => 'reported'], [], true);
        app(EntryService::class)->review($seniorLead, $entry, 'APPROVED', null);
        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);
        $entry->currentRevision->evidence()->create([
            'user_id' => $programmeLead->id,
            'disk' => 'local',
            'path' => 'evidence/test/target-change.pdf',
            'original_name' => 'target-change.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ]);
        $approvedRevisionId = $entry->fresh()->approved_revision_id;

        $targets->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'value' => 120]);
        $entry->refresh();

        $this->assertSame(WorkflowStatus::ReEntryRequired, $entry->status);
        $this->assertNull($entry->approved_revision_id);
        $this->assertNotSame($approvedRevisionId, $entry->current_revision_id);
        $this->assertSame(2, $entry->revisions()->count());
        $this->assertNull($entry->currentRevision->numeric_value);
        $this->assertSame('evidence/test/target-change.pdf', $entry->currentRevision->evidence()->value('path'));
        $this->assertDatabaseHas('indicator_target_revisions', ['indicator_target_id' => IndicatorTarget::first()->id, 'action' => 'updated']);
    }

    public function test_target_changes_keep_drafts_as_drafts_return_pending_entries_and_mark_removed_obligations(): void
    {
        [$assignment, , , $programmeLead] = $this->fixture();
        $targets = app(TargetService::class);
        $entries = app(EntryService::class);

        $targets->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'value' => 10]);
        $draft = $entries->save($programmeLead, $assignment, '2026-10-01', [
            'numeric_value' => 10,
            'value_state' => 'reported',
        ], [], false);
        $targets->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'value' => 11]);

        $this->assertSame(WorkflowStatus::Draft, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->currentRevision->numeric_value);

        $targets->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-11-01', 'value' => 20]);
        $pending = $entries->save($programmeLead, $assignment, '2026-11-01', [
            'numeric_value' => 20,
            'value_state' => 'reported',
        ], [], true);
        $targets->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-11-01', 'value' => 21]);

        $this->assertSame(WorkflowStatus::ReturnedForCorrection, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->currentRevision->numeric_value);

        $targets->save(['indicator_assignment_id' => $assignment->id, 'period' => '2026-12-01', 'value' => 30]);
        $removed = $entries->save($programmeLead, $assignment, '2026-12-01', [
            'numeric_value' => 30,
            'value_state' => 'reported',
        ], [], false);
        $targets->clear($assignment->id, '2026-12-01');

        $this->assertSame(WorkflowStatus::TargetRemoved, $removed->fresh()->status);
        $this->assertNull($removed->fresh()->approved_revision_id);
    }

    public function test_quarterly_report_aggregates_monthly_targets_and_only_approved_results(): void
    {
        [$assignment, , $mneHead, $programmeLead, $seniorLead] = $this->fixture();
        foreach (['2026-07-01' => 10, '2026-08-01' => 20, '2026-09-01' => 30] as $period => $target) {
            app(TargetService::class)->save(['indicator_assignment_id' => $assignment->id, 'period' => $period, 'value' => $target]);
        }
        foreach (['2026-07-01' => 12, '2026-08-01' => 18] as $period => $achievement) {
            $entry = app(EntryService::class)->save($programmeLead, $assignment, $period, [
                'numeric_value' => $achievement,
                'value_state' => 'reported',
                'variance_explanation' => 'Monthly achievement differed from the plan.',
            ], [], true);
            app(EntryService::class)->review($seniorLead, $entry, 'APPROVED', null);
            app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);
        }

        $row = app(ReportingService::class)->report($mneHead, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-09-30'))->first();

        $this->assertSame(60.0, $row['target']);
        $this->assertSame(30.0, $row['achievement']);
        $this->assertSame(3, $row['expected_months']);
        $this->assertSame(2, $row['approved_months']);
        $this->assertSame('INCOMPLETE 2/3', $row['status']);
    }

    public function test_programme_level_assignment_is_available_to_a_programme_assigned_user(): void
    {
        [$assignment, , , $programmeLead] = $this->fixture();
        $indicator = IndicatorDefinition::create([
            'project_id' => $assignment->indicator->project_id, 'code' => 'PROGRAMME-OUTCOME', 'name' => 'Programme outcome',
            'description' => 'Programme-wide test indicator', 'category' => 'Outcome', 'unit' => '%', 'data_type' => 'percentage',
            'frequency' => 'monthly', 'reporting_scope' => 'programme', 'time_aggregation' => 'AVERAGE',
            'centre_aggregation' => 'NON-AGGREGATABLE', 'target_tracking' => true, 'active' => true,
        ]);
        $programmeAssignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => null, 'required' => true]);
        app(TargetService::class)->save(['indicator_assignment_id' => $programmeAssignment->id, 'period' => '2026-10-01', 'value' => 10]);

        $this->actingAs($programmeLead)
            ->get(route('entries.index', ['period_type' => 'monthly', 'anchor' => '2026-10']))
            ->assertOk()->assertSee('Programme outcome')->assertSee('Programme level');
    }

    public function test_project_percentage_results_and_targets_are_combined_by_numerator_and_denominator(): void
    {
        [$assignment, , $mneHead, $programmeLead, $seniorLead] = $this->fixture();
        $project = $assignment->indicator->project;
        $secondCentre = Centre::create(['code' => 'P-2', 'name' => 'Project Two', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($secondCentre);
        foreach ([$programmeLead, $seniorLead] as $user) {
            $user->centres()->attach($secondCentre);
        }
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'WEIGHTED-PERCENTAGE',
            'name' => 'Students passing assessment',
            'description' => 'Combined from project numerators and denominators.',
            'category' => 'Outcome',
            'unit' => '%',
            'data_type' => 'percentage',
            'calculation_method' => 'ratio_percentage',
            'frequency' => 'monthly',
            'reporting_scope' => 'project',
            'time_aggregation' => 'RATIO',
            'centre_aggregation' => 'RATIO',
            'direction' => 'higher',
            'target_tracking' => true,
            'approval_required' => true,
            'active' => true,
        ]);
        $firstAssignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $assignment->centre_id, 'required' => true]);
        $secondAssignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $secondCentre->id, 'required' => true]);
        $targets = app(TargetService::class);
        $targets->save(['indicator_assignment_id' => $firstAssignment->id, 'period' => '2026-10-01', 'target_numerator' => 1, 'target_denominator' => 2]);
        $targets->save(['indicator_assignment_id' => $secondAssignment->id, 'period' => '2026-10-01', 'target_numerator' => 9, 'target_denominator' => 10]);

        foreach ([[$firstAssignment, 1, 4], [$secondAssignment, 9, 10]] as [$projectAssignment, $numerator, $denominator]) {
            $entry = app(EntryService::class)->save($programmeLead, $projectAssignment, '2026-10-01', [
                'numerator' => $numerator,
                'denominator' => $denominator,
                'value_state' => 'reported',
                'variance_explanation' => 'Project result differed from its monthly target.',
            ], [], true);
            app(EntryService::class)->review($seniorLead, $entry, 'APPROVED', null);
            app(EntryService::class)->review($mneHead, $entry, 'APPROVED', null);
        }

        $row = app(ReportingService::class)->consolidatedReport(
            $mneHead,
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-31'),
            $project->id,
            $indicator->id,
        )->first();

        $this->assertEqualsWithDelta(83.3333, $row['target'], 0.0001);
        $this->assertEqualsWithDelta(71.4286, $row['achievement'], 0.0001);
        $this->assertSame(10.0, (float) $row['numerator']);
        $this->assertSame(14.0, (float) $row['denominator']);
    }

    public function test_excel_target_import_is_previewed_before_changes_are_applied(): void
    {
        [$assignment, $admin] = $this->fixture();
        $response = $this->actingAs($admin)->get(route('targets.template', ['project' => $assignment->indicator->project, 'financial_year' => 2026]));
        $response->assertOk();
        $path = storage_path('framework/testing/target-plan-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');
        $this->assertSame('Indicator', $sheet->getCell('A4')->getValue());
        $this->assertSame('Apr', $sheet->getCell('B4')->getValue());
        $this->assertSame('Mar', $sheet->getCell('M4')->getValue());
        $this->assertFalse($sheet->getColumnDimension('N')->getVisible());
        $this->assertFalse($sheet->getColumnDimension('O')->getVisible());
        $assignmentRow = $this->assignmentRow($sheet, $assignment->id);
        $sheet->setCellValue('B'.$assignmentRow, '0');
        $sheet->setCellValue('C'.$assignmentRow, '25');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'targets.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $uploadResponse = $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => $assignment->indicator->project_id, 'financial_year' => 2026, 'file' => $upload,
        ]);
        $batch = TargetImportBatch::firstOrFail();
        $uploadResponse->assertRedirect(route('targets.imports.show', $batch));
        $this->assertDatabaseCount('indicator_targets', 0);
        $this->assertSame(2, $batch->rows()->count());

        $this->actingAs($admin)->post(route('targets.imports.confirm', $batch), ['confirmation' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([0.0, 25.0], IndicatorTarget::orderBy('period')->get()->map(fn (IndicatorTarget $target): float => (float) $target->value)->all());
        @unlink($path);
    }

    public function test_unchanged_prefilled_targets_are_ignored_when_new_targets_are_imported(): void
    {
        [$assignment, $admin] = $this->fixture();
        app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01',
            'value' => 90,
            'notes' => 'Internal target note.',
        ]);
        $response = $this->actingAs($admin)->get(route('targets.template', [
            'project' => $assignment->indicator->project,
            'financial_year' => 2026,
        ]));
        $path = storage_path('framework/testing/unchanged-prefilled-targets-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');
        $assignmentRow = $this->assignmentRow($sheet, $assignment->id);
        $this->assertSame('90', (string) $sheet->getCell('H'.$assignmentRow)->getValue());
        $sheet->setCellValue('B'.$assignmentRow, '80');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'mixed-targets.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => $assignment->indicator->project_id,
            'financial_year' => 2026,
            'file' => $upload,
        ])->assertSessionHasNoErrors();
        $batch = TargetImportBatch::firstOrFail();

        $this->assertSame(1, $batch->rows()->count());
        $this->assertDatabaseHas('target_import_rows', [
            'target_import_batch_id' => $batch->id,
            'action' => 'create',
            'period' => '2026-04-01 00:00:00',
        ]);
        $this->assertDatabaseMissing('target_import_rows', [
            'target_import_batch_id' => $batch->id,
            'period' => '2026-10-01 00:00:00',
        ]);

        $this->actingAs($admin)
            ->post(route('targets.imports.confirm', $batch), ['confirmation' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('indicator_targets', [
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-04-01 00:00:00',
            'value' => 80,
        ]);
        $this->assertDatabaseHas('indicator_targets', [
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01 00:00:00',
            'value' => 90,
            'notes' => 'Internal target note.',
        ]);
        @unlink($path);
    }

    public function test_existing_target_update_survives_json_numeric_normalization_and_preserves_notes(): void
    {
        [$assignment, $admin] = $this->fixture();
        app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01 00:00:00',
            'value' => 90,
            'notes' => 'Internal target note.',
        ]);
        $response = $this->actingAs($admin)->get(route('targets.template', [
            'project' => $assignment->indicator->project,
            'financial_year' => 2026,
        ]));
        $path = storage_path('framework/testing/normalized-target-update-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');
        $sheet->setCellValue('H'.$this->assignmentRow($sheet, $assignment->id), '95');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'updated-target.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => $assignment->indicator->project_id,
            'financial_year' => 2026,
            'file' => $upload,
        ])->assertSessionHasNoErrors();
        $batch = TargetImportBatch::firstOrFail();
        $this->assertDatabaseHas('target_import_rows', [
            'target_import_batch_id' => $batch->id,
            'action' => 'update',
            'period' => '2026-10-01 00:00:00',
        ]);

        $this->actingAs($admin)
            ->post(route('targets.imports.confirm', $batch), ['confirmation' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('indicator_targets', [
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01 00:00:00',
            'value' => 95,
            'notes' => 'Internal target note.',
        ]);
        @unlink($path);
    }

    public function test_confirmation_still_blocks_a_genuine_target_change_after_preview(): void
    {
        [$assignment, $admin] = $this->fixture();
        $targets = app(TargetService::class);
        $targets->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01',
            'value' => 90,
        ]);
        $response = $this->actingAs($admin)->get(route('targets.template', [
            'project' => $assignment->indicator->project,
            'financial_year' => 2026,
        ]));
        $path = storage_path('framework/testing/concurrent-target-change-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');
        $sheet->setCellValue('H'.$this->assignmentRow($sheet, $assignment->id), '95');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'concurrent-target.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => $assignment->indicator->project_id,
            'financial_year' => 2026,
            'file' => $upload,
        ])->assertSessionHasNoErrors();
        $batch = TargetImportBatch::firstOrFail();

        $targets->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01',
            'value' => 91,
        ]);

        $this->actingAs($admin)
            ->post(route('targets.imports.confirm', $batch), ['confirmation' => '1'])
            ->assertSessionHasErrors('batch');
        $this->assertSame('preview', $batch->fresh()->status);
        $this->assertDatabaseHas('indicator_targets', [
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01 00:00:00',
            'value' => 91,
        ]);
        @unlink($path);
    }

    public function test_invalid_target_spreadsheet_is_rejected_and_the_stored_upload_is_removed(): void
    {
        Storage::fake('local');
        [$assignment, $admin] = $this->fixture();
        $response = $this->actingAs($admin)->get(route('targets.template', [
            'project' => $assignment->indicator->project,
            'financial_year' => 2026,
        ]));
        $path = storage_path('framework/testing/invalid-target-plan-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getSheetByName('Targets')->setCellValue('A4', 'Changed header');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'invalid-targets.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => $assignment->indicator->project_id,
            'financial_year' => 2026,
            'file' => $upload,
        ])->assertSessionHasErrors('file');

        $this->assertSame([], Storage::disk('local')->files('target-imports'));
        @unlink($path);
    }

    public function test_all_programmes_target_template_is_previewed_and_applied_as_one_atomic_import(): void
    {
        [$firstAssignment, $admin] = $this->fixture();
        $programme = Programme::create(['name' => 'Health', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'HLT', 'name' => 'Health', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'H-1', 'name' => 'Health Project', 'type' => 'Health Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'HEALTH-ACTIVITY',
            'name' => 'Health sessions',
            'description' => 'Test indicator',
            'category' => 'Activity',
            'unit' => 'sessions',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'reporting_scope' => 'project',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'target_tracking' => true,
            'approval_required' => true,
            'active' => true,
        ]);
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $indicator->id,
            'centre_id' => $centre->id,
            'required' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('targets.template.all', ['financial_year' => 2026]));
        $response->assertOk();
        $path = storage_path('framework/testing/all-programme-targets-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');
        $this->assertSame('All programmes', $sheet->getCell('B2')->getValue());
        $groupLabels = collect(range(5, $sheet->getHighestRow()))
            ->filter(fn (int $row): bool => blank($sheet->getCell('N'.$row)->getValue()))
            ->map(fn (int $row): mixed => $sheet->getCell('A'.$row)->getValue())
            ->filter()
            ->values();
        $this->assertTrue($groupLabels->contains('Education'));
        $this->assertTrue($groupLabels->contains('Health'));
        $this->assertTrue($groupLabels->contains('Project One'));
        $this->assertTrue($groupLabels->contains('Health Project'));
        $sheet->setCellValue('B'.$this->assignmentRow($sheet, $firstAssignment->id), '15');
        $sheet->setCellValue('B'.$this->assignmentRow($sheet, $secondAssignment->id), '25');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'all-programmes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $uploadResponse = $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => 'all',
            'financial_year' => 2026,
            'file' => $upload,
        ]);
        $batch = TargetImportBatch::firstOrFail();
        $uploadResponse->assertRedirect(route('targets.imports.show', $batch));
        $this->assertNull($batch->project_id);
        $this->assertSame(2, $batch->rows()->count());
        $this->actingAs($admin)
            ->get(route('targets.imports.show', $batch))
            ->assertOk()
            ->assertSee('All programmes')
            ->assertSee('Education')
            ->assertSee('Health');

        $this->actingAs($admin)
            ->post(route('targets.imports.confirm', $batch), ['confirmation' => '1'])
            ->assertRedirect(route('targets.index', ['programme' => 'all', 'financial_year' => 2026]));
        $this->assertDatabaseHas('indicator_targets', [
            'indicator_assignment_id' => $firstAssignment->id,
            'value' => 15,
        ]);
        $this->assertDatabaseHas('indicator_targets', [
            'indicator_assignment_id' => $secondAssignment->id,
            'value' => 25,
        ]);
        @unlink($path);
    }

    public function test_project_scoped_template_contains_only_that_projects_indicators(): void
    {
        [$firstAssignment, $admin] = $this->fixture();
        $secondCentre = Centre::create(['code' => 'P-2', 'name' => 'Project Two', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $firstAssignment->indicator->project->centres()->attach($secondCentre);
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $firstAssignment->indicator_definition_id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('targets.template', [
            'project' => $firstAssignment->indicator->project,
            'centre' => $secondCentre->id,
            'financial_year' => 2026,
        ]));
        $path = storage_path('framework/testing/project-targets-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');

        $this->assertSame('Project Two', $sheet->getCell('B2')->getValue());
        $secondAssignmentRow = $this->assignmentRow($sheet, $secondAssignment->id);
        $this->assertSame($secondAssignment->id, (int) $sheet->getCell('N'.$secondAssignmentRow)->getValue());
        $this->assertNull($this->findAssignmentRow($sheet, $firstAssignment->id));
        $sheet->setCellValue('B'.$secondAssignmentRow, '17');
        (new Xlsx($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        $upload = new UploadedFile($path, 'project-targets.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $uploadResponse = $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => $firstAssignment->indicator->project_id,
            'centre' => $secondCentre->id,
            'financial_year' => 2026,
            'file' => $upload,
        ]);
        $batch = TargetImportBatch::firstOrFail();
        $uploadResponse->assertRedirect(route('targets.imports.show', $batch));
        $this->assertSame($secondCentre->id, $batch->centre_id);
        $this->actingAs($admin)->post(route('targets.imports.confirm', $batch), ['confirmation' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('indicator_targets', ['indicator_assignment_id' => $secondAssignment->id, 'value' => 17]);
        $this->assertDatabaseMissing('indicator_targets', ['indicator_assignment_id' => $firstAssignment->id]);
        @unlink($path);
    }

    public function test_donor_filter_limits_target_page_template_and_import_to_funded_projects(): void
    {
        [$firstAssignment, $admin] = $this->fixture();
        $project = $firstAssignment->indicator->project;
        $secondCentre = Centre::create(['code' => 'P-2', 'name' => 'Project Two', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($secondCentre);
        $secondAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $firstAssignment->indicator_definition_id,
            'centre_id' => $secondCentre->id,
            'required' => true,
        ]);
        $selectedDonor = Donor::factory()->create(['name' => 'Selected Donor']);
        $otherDonor = Donor::factory()->create(['name' => 'Other Donor']);
        CentreDonor::create([
            'centre_id' => $firstAssignment->centre_id,
            'donor_id' => $selectedDonor->id,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_primary' => true,
        ]);
        CentreDonor::create([
            'centre_id' => $secondCentre->id,
            'donor_id' => $otherDonor->id,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_primary' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('targets.index', [
                'programme' => $project->id,
                'financial_year' => 2026,
                'donor' => $selectedDonor->name,
            ]))
            ->assertOk()
            ->assertSee('Donor')
            ->assertSee('Project One')
            ->assertDontSee('Project Two');

        $response = $this->actingAs($admin)->get(route('targets.template', [
            'project' => $project,
            'financial_year' => 2026,
            'donor' => $selectedDonor->name,
        ]));
        $response->assertOk();
        $path = storage_path('framework/testing/donor-targets-'.uniqid().'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Targets');
        $this->assertSame('Education · Donor: Selected Donor', $sheet->getCell('B2')->getValue());
        $this->assertNotNull($this->findAssignmentRow($sheet, $firstAssignment->id));
        $this->assertNull($this->findAssignmentRow($sheet, $secondAssignment->id));
        $sheet->setCellValue('B'.$this->assignmentRow($sheet, $firstAssignment->id), '35');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'donor-targets.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $uploadResponse = $this->actingAs($admin)->post(route('targets.imports.upload'), [
            'programme' => $project->id,
            'financial_year' => 2026,
            'donor' => $selectedDonor->name,
            'file' => $upload,
        ]);
        $batch = TargetImportBatch::firstOrFail();
        $uploadResponse->assertRedirect(route('targets.imports.show', ['batch' => $batch, 'donor' => $selectedDonor->name]));
        $this->assertSame(1, $batch->rows()->count());

        $this->actingAs($admin)
            ->post(route('targets.imports.confirm', $batch), ['confirmation' => '1', 'donor' => $selectedDonor->name])
            ->assertRedirect(route('targets.index', [
                'programme' => $project->id,
                'financial_year' => 2026,
                'donor' => $selectedDonor->name,
            ]));
        $this->assertDatabaseHas('indicator_targets', ['indicator_assignment_id' => $firstAssignment->id, 'value' => 35]);
        $this->assertDatabaseMissing('indicator_targets', ['indicator_assignment_id' => $secondAssignment->id]);
        @unlink($path);
    }

    private function assignmentRow(Worksheet $sheet, int $assignmentId): int
    {
        $row = $this->findAssignmentRow($sheet, $assignmentId);
        $this->assertNotNull($row, "Assignment {$assignmentId} was not found in the target template.");

        return $row;
    }

    private function findAssignmentRow(Worksheet $sheet, int $assignmentId): ?int
    {
        foreach (range(self::templateDataStartRow(), $sheet->getHighestRow()) as $row) {
            if ((int) $sheet->getCell('N'.$row)->getValue() === $assignmentId) {
                return $row;
            }
        }

        return null;
    }

    private static function templateDataStartRow(): int
    {
        return 5;
    }

    /** @return array{IndicatorAssignment, User, User, User, User} */
    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $mneHead = User::factory()->create(['role' => 'M&E HEAD', 'active' => true]);
        $programmeLead = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $seniorLead = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'P-1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        foreach ([$programmeLead, $seniorLead] as $user) {
            $user->projects()->attach($project);
            $user->centres()->attach($centre);
        }
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id, 'code' => 'MONTHLY-OUTPUT', 'name' => 'Monthly output', 'description' => 'Test indicator',
            'category' => 'Output', 'unit' => 'people', 'data_type' => 'integer', 'frequency' => 'monthly', 'reporting_scope' => 'project',
            'time_aggregation' => 'SUM', 'centre_aggregation' => 'SUM', 'target_tracking' => true, 'approval_required' => true, 'active' => true,
        ]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);

        return [$assignment->load(['indicator.project', 'centre']), $admin, $mneHead, $programmeLead, $seniorLead];
    }
}
