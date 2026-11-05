<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\EntryService;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ApprovalSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_without_an_approval_role_cannot_download_or_upload_the_approval_spreadsheet(): void
    {
        [, $submitter] = $this->fixture();

        $this->actingAs($submitter)
            ->get(route('approvals.index'))
            ->assertForbidden();

        $this->actingAs($submitter)
            ->get(route('approvals.excel.download'))
            ->assertForbidden();

        $this->actingAs($submitter)
            ->post(route('approvals.excel.upload'), [
                'file' => UploadedFile::fake()->create(
                    'approvals.xlsx',
                    10,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ),
            ])
            ->assertForbidden();
    }

    public function test_mne_head_is_shown_the_excel_approval_controls(): void
    {
        [, , , $mneHead] = $this->fixture();

        $this->actingAs($mneHead)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Approval with Excel')
            ->assertSee(route('approvals.excel.download'), false)
            ->assertSee(route('approvals.excel.upload'), false);
    }

    public function test_senior_programme_lead_can_export_and_approve_first_stage_items(): void
    {
        [$assignment, $submitter, $seniorLead] = $this->fixture();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);

        $response = $this->actingAs($seniorLead)->get(route('approvals.excel.download'));
        $response->assertOk();

        $path = $this->temporarySpreadsheetPath();
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Approvals');
        $this->assertSame('Pending Senior Programme Lead', $sheet->getCell('J2')->getValue());
        $this->assertSame($entry->id, $sheet->getCell('M2')->getValue());
        $sheet->setCellValue('K2', 'Yes');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile(
            $path,
            'approvals.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
        $this->actingAs($seniorLead)
            ->post(route('approvals.excel.upload'), ['file' => $upload])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 submission(s) approved at their current stage from the spreadsheet. 0 skipped because they changed or no longer met the approval requirements.');

        $this->assertSame(WorkflowStatus::PendingMneHead, $entry->fresh()->status);
        $this->assertDatabaseHas('approval_actions', [
            'entry_revision_id' => $entry->current_revision_id,
            'user_id' => $seniorLead->id,
            'stage' => 'SENIOR PROGRAM LEAD',
            'action' => WorkflowStatus::PendingMneHead,
        ]);

        @unlink($path);
    }

    public function test_administrator_export_contains_both_pending_approval_stages(): void
    {
        [$assignment, $submitter, $seniorLead] = $this->fixture();
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $mneEntry = $this->pendingMneEntry($assignment, $submitter, $seniorLead, '2026-10-01', 100);
        $seniorEntry = app(EntryService::class)->save($submitter, $assignment, '2026-11-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);

        $response = $this->actingAs($administrator)->get(route('approvals.excel.download'));
        $response->assertOk();

        $path = $this->temporarySpreadsheetPath();
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Approvals');
        $this->assertSame($mneEntry->id, $sheet->getCell('M2')->getValue());
        $this->assertSame('Pending M&E Head', $sheet->getCell('J2')->getValue());
        $this->assertSame($seniorEntry->id, $sheet->getCell('M3')->getValue());
        $this->assertSame('Pending Senior Programme Lead', $sheet->getCell('J3')->getValue());
        $sheet->setCellValue('K2', 'Yes');
        $sheet->setCellValue('K3', 'Yes');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile(
            $path,
            'approvals.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
        $this->actingAs($administrator)
            ->post(route('approvals.excel.upload'), ['file' => $upload])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '2 submission(s) approved at their current stage from the spreadsheet. 0 skipped because they changed or no longer met the approval requirements.');

        $this->assertSame(WorkflowStatus::FinalApproved, $mneEntry->fresh()->status);
        $this->assertSame(WorkflowStatus::PendingMneHead, $seniorEntry->fresh()->status);

        @unlink($path);
    }

    public function test_mne_head_can_export_pending_approvals_and_upload_yes_to_final_approve(): void
    {
        [$assignment, $submitter, $seniorLead, $mneHead] = $this->fixture();
        $entry = $this->pendingMneEntry($assignment, $submitter, $seniorLead, '2026-10-01', 105);
        app(EntryService::class)->save($submitter, $assignment, '2026-11-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);

        $response = $this->actingAs($mneHead)->get(route('approvals.excel.download'));
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = $this->temporarySpreadsheetPath();
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Approvals');
        $this->assertNotNull($sheet);
        $this->assertSame('Approve', $sheet->getCell('K1')->getValue());
        $this->assertSame($entry->id, $sheet->getCell('M2')->getValue());
        $this->assertSame($entry->current_revision_id, $sheet->getCell('N2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());
        $this->assertFalse($sheet->getColumnDimension('M')->getVisible());
        $this->assertSame('"Yes,No"', $sheet->getDataValidation('K2')->getFormula1());
        $sheet->setCellValue('K2', 'Yes');
        $sheet->setCellValue('L2', 'Spreadsheet review complete.');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile(
            $path,
            'approvals.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
        $this->actingAs($mneHead)
            ->post(route('approvals.excel.upload'), ['file' => $upload])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 submission(s) approved at their current stage from the spreadsheet. 0 skipped because they changed or no longer met the approval requirements.');

        $this->assertSame(WorkflowStatus::FinalApproved, $entry->fresh()->status);
        $this->assertSame($entry->current_revision_id, $entry->fresh()->approved_revision_id);
        $this->assertDatabaseHas('approval_actions', [
            'entry_revision_id' => $entry->current_revision_id,
            'user_id' => $mneHead->id,
            'stage' => 'M&E HEAD',
            'action' => WorkflowStatus::FinalApproved,
            'comment' => 'Spreadsheet review complete.',
        ]);

        @unlink($path);
    }

    public function test_upload_rejects_changed_protected_identifiers_without_approving_anything(): void
    {
        [$assignment, $submitter, $seniorLead, $mneHead] = $this->fixture();
        $entry = $this->pendingMneEntry($assignment, $submitter, $seniorLead, '2026-10-01', 100);

        $response = $this->actingAs($mneHead)->get(route('approvals.excel.download'));
        $path = $this->temporarySpreadsheetPath();
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Approvals');
        $sheet->setCellValue('K2', 'Yes');
        $sheet->setCellValue('M2', $entry->id + 999);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile(
            $path,
            'approvals.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
        $this->actingAs($mneHead)
            ->post(route('approvals.excel.upload'), ['file' => $upload])
            ->assertSessionHasErrors('file');

        $this->assertSame(WorkflowStatus::PendingMneHead, $entry->fresh()->status);
        $this->assertDatabaseMissing('approval_actions', [
            'entry_revision_id' => $entry->current_revision_id,
            'user_id' => $mneHead->id,
            'stage' => 'M&E HEAD',
        ]);

        @unlink($path);
    }

    public function test_upload_safely_skips_a_submission_that_was_approved_after_download(): void
    {
        [$assignment, $submitter, $seniorLead, $mneHead] = $this->fixture();
        $entry = $this->pendingMneEntry($assignment, $submitter, $seniorLead, '2026-10-01', 100);

        $response = $this->actingAs($mneHead)->get(route('approvals.excel.download'));
        $path = $this->temporarySpreadsheetPath();
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Approvals');
        $sheet->setCellValue('K2', 'Yes');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', 'Approved on screen first.');
        $upload = new UploadedFile(
            $path,
            'approvals.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $this->actingAs($mneHead)
            ->post(route('approvals.excel.upload'), ['file' => $upload])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '0 submission(s) approved at their current stage from the spreadsheet. 1 skipped because they changed or no longer met the approval requirements.');

        $this->assertSame(1, $entry->currentRevision->actions()->where('stage', 'M&E HEAD')->count());
        @unlink($path);
    }

    private function pendingMneEntry(
        IndicatorAssignment $assignment,
        User $submitter,
        User $seniorLead,
        string $period,
        int $value,
    ): IndicatorEntry {
        $entry = app(EntryService::class)->save($submitter, $assignment, $period, [
            'value_state' => 'reported',
            'numeric_value' => $value,
            'variance_explanation' => $value === 100 ? null : 'Participation exceeded the planned value.',
        ], [], true);
        app(EntryService::class)->review($seniorLead, $entry, 'APPROVED', null);

        return $entry->fresh('currentRevision');
    }

    private function temporarySpreadsheetPath(): string
    {
        return storage_path('framework/testing/approval-spreadsheet-'.uniqid().'.xlsx');
    }

    /** @return array{IndicatorAssignment, User, User, User} */
    private function fixture(): array
    {
        $submitter = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $seniorLead = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD', 'active' => true]);
        $mneHead = User::factory()->create(['role' => 'M&E HEAD', 'active' => true]);
        $programme = Programme::query()->create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::query()->create([
            'programme_id' => $programme->id,
            'code' => 'EDU',
            'name' => 'Education',
            'description' => 'Test',
        ]);
        $centre = Centre::query()->create([
            'code' => 'ORG-1',
            'name' => 'Project One',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $project->centres()->attach($centre);
        foreach ([$submitter, $seniorLead] as $user) {
            $user->projects()->attach($project);
            $user->centres()->attach($centre);
        }
        $indicator = IndicatorDefinition::query()->create([
            'project_id' => $project->id,
            'code' => 'PARTICIPATION',
            'name' => 'Participation',
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
        $assignment = IndicatorAssignment::query()->create([
            'indicator_definition_id' => $indicator->id,
            'centre_id' => $centre->id,
            'required' => true,
        ]);
        foreach (['2026-10-01', '2026-11-01'] as $period) {
            IndicatorTarget::create([
                'indicator_assignment_id' => $assignment->id,
                'period' => $period,
                'period_end' => CarbonImmutable::parse($period)->endOfMonth(),
                'period_type' => 'monthly',
                'target_type' => 'numeric',
                'value' => 100,
                'active' => true,
            ]);
        }

        return [$assignment->load(['indicator.project', 'centre']), $submitter, $seniorLead, $mneHead];
    }
}
