<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_can_be_mapped_validated_and_imported_as_a_draft(): void
    {
        [$user, $project] = $this->configuredProject();
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('history.csv', "Location,KPI,Month,Result,Comment\nC1,TEST-1,2026-09-01,18,Imported result\n");

        $batch = app(ImportService::class)->upload($user, $project, $file);
        $batch = app(ImportService::class)->validateRows($batch, [
            'centre' => 'Location',
            'indicator' => 'KPI',
            'period' => 'Month',
            'value' => 'Result',
            'numerator' => null,
            'denominator' => null,
            'notes' => 'Comment',
        ]);

        $this->assertSame('valid', $batch->rows->first()->status);
        app(ImportService::class)->confirm($user, $batch);

        $this->assertDatabaseHas('indicator_entries', ['period' => '2026-09-01 00:00:00', 'status' => 'DRAFT']);
        $this->assertDatabaseHas('entry_revisions', ['numeric_value' => 18, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('import_rows', ['import_batch_id' => $batch->id, 'status' => 'imported']);
    }

    public function test_same_file_cannot_be_uploaded_twice_for_a_project(): void
    {
        [$user, $project] = $this->configuredProject();
        Storage::fake('local');
        $content = "centre,indicator,period,value\nC1,TEST-1,2026-09-01,18\n";
        app(ImportService::class)->upload($user, $project, UploadedFile::fake()->createWithContent('history.csv', $content));

        $this->expectException(ValidationException::class);
        app(ImportService::class)->upload($user, $project, UploadedFile::fake()->createWithContent('copy.csv', $content));
    }

    public function test_xlsx_file_can_be_uploaded_and_previewed(): void
    {
        [$user, $project] = $this->configuredProject();
        Storage::fake('local');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['centre', 'indicator', 'period', 'value'],
            ['C1', 'TEST-1', '2026-09-01', 18],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'aasra-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $file = new UploadedFile($path, 'history.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $batch = app(ImportService::class)->upload($user, $project, $file);

            $this->assertSame(['centre', 'indicator', 'period', 'value'], $batch->columns);
            $this->assertSame('C1', $batch->rows->first()->source['centre']);
        } finally {
            @unlink($path);
        }
    }

    public function test_existing_operational_record_is_marked_as_duplicate(): void
    {
        [$user, $project, $assignment] = $this->configuredProject();
        Storage::fake('local');
        $assignment->entries()->create(['period' => '2026-09-01', 'status' => 'DRAFT']);
        $batch = app(ImportService::class)->upload($user, $project, UploadedFile::fake()->createWithContent('history.csv', "centre,indicator,period,value\nC1,TEST-1,2026-09-01,18\n"));
        $batch = app(ImportService::class)->validateRows($batch, [
            'centre' => 'centre',
            'indicator' => 'indicator',
            'period' => 'period',
            'value' => 'value',
            'numerator' => null,
            'denominator' => null,
            'notes' => null,
        ]);

        $this->assertSame('duplicate', $batch->rows->first()->status);
    }

    public function test_import_rejects_reporting_periods_before_the_indicator_start_month(): void
    {
        [$user, $project, $assignment] = $this->configuredProject();
        $assignment->indicator->update(['effective_date' => '2026-09-01']);
        Storage::fake('local');
        $batch = app(ImportService::class)->upload($user, $project, UploadedFile::fake()->createWithContent('history.csv', "centre,indicator,period,value\nC1,TEST-1,2026-08-01,18\n"));
        $batch = app(ImportService::class)->validateRows($batch, [
            'centre' => 'centre',
            'indicator' => 'indicator',
            'period' => 'period',
            'value' => 'value',
            'numerator' => null,
            'denominator' => null,
            'notes' => null,
        ]);

        $this->assertSame('invalid', $batch->rows->first()->status);
        $this->assertSame('Reporting for this indicator starts in September 2026.', $batch->rows->first()->error);
    }

    public function test_import_normalizes_dates_within_a_quarter_and_rejects_a_second_row_for_that_quarter(): void
    {
        [$user, $project, $assignment] = $this->configuredProject();
        $assignment->indicator->update([
            'frequency' => 'quarterly',
            'effective_date' => '2026-04-01',
        ]);
        Storage::fake('local');
        $batch = app(ImportService::class)->upload($user, $project, UploadedFile::fake()->createWithContent(
            'quarterly.csv',
            "centre,indicator,period,value\nC1,TEST-1,2026-05-15,18\nC1,TEST-1,2026-06-20,22\n",
        ));
        $batch = app(ImportService::class)->validateRows($batch, [
            'centre' => 'centre',
            'indicator' => 'indicator',
            'period' => 'period',
            'value' => 'value',
            'numerator' => null,
            'denominator' => null,
            'notes' => null,
        ]);

        $this->assertSame('valid', $batch->rows[0]->status);
        $this->assertSame('2026-04-01', $batch->rows[0]->normalized['period']);
        $this->assertSame('duplicate', $batch->rows[1]->status);
    }

    public function test_authorized_user_can_open_import_history_and_download_template(): void
    {
        [$user, $project] = $this->configuredProject();

        $this->actingAs($user)->get(route('imports.index'))->assertOk();
        $this->actingAs($user)->get(route('imports.template', [$project, 'format' => 'csv']))->assertOk();
        $this->actingAs($user)->get(route('imports.template', [$project, 'format' => 'xlsx']))->assertOk();
    }

    /** @return array{User, Project, IndicatorAssignment} */
    private function configuredProject(): array
    {
        $user = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Test programme', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'TEST', 'name' => 'Test project', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'C1', 'name' => 'Test centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $user->projects()->attach($project);
        $user->centres()->attach($centre);
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
            'active' => true,
        ]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);

        return [$user, $project, $assignment];
    }
}
