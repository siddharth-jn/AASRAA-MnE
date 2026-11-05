<?php

namespace Tests\Feature;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Filament\Resources\IndicatorDefinitions\Pages\ListIndicatorDefinitions;
use App\Models\Centre;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorImportBatch;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class IndicatorImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_selects_a_programme_and_downloads_a_simplified_project_template(): void
    {
        [$admin, , $project, $firstCentre, $secondCentre] = $this->fixture();

        Livewire::actingAs($admin)
            ->test(ListIndicatorDefinitions::class)
            ->assertActionExists('importIndicators');

        $page = $this->actingAs($admin)
            ->get(IndicatorDefinitionResource::getUrl('import', [
                'programme' => $project->id,
                'scope' => 'project',
                'centre' => $firstCentre->id,
            ]));
        $page
            ->assertOk()
            ->assertSee('Choose where the indicators belong')
            ->assertSee($firstCentre->name)
            ->assertSee($secondCentre->name)
            ->assertSee('Import for '.$firstCentre->name)
            ->assertSee('Download XLSX template')
            ->assertSee('What will be applied to every indicator')
            ->assertSee('the exact column, the rejected value, and how to correct it')
            ->assertSee('indicator-history-table', false)
            ->assertSee('data-no-wire-navigate', false);
        $this->assertSame(1, substr_count($page->getContent(), 'Download XLSX template'));

        $response = $this->actingAs($admin)->get(route('indicator-imports.template.select', [
            'project_id' => $project->id,
            'scope' => 'project',
            'centre_id' => $firstCentre->id,
        ]));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $content = $response->streamedContent();
        $this->assertStringStartsWith('PK', $content);
        $this->assertGreaterThan(1000, strlen($content));
        $path = $this->saveResponse($content);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Indicators');
        $metadata = $spreadsheet->getSheetByName('Metadata');

        $this->assertSame('Indicator name', $sheet->getCell('A5')->getValue());
        $this->assertSame('Data sources', $sheet->getCell('E5')->getValue());
        $this->assertSame('Accepted options (select/status only)', $sheet->getCell('F5')->getValue());
        $this->assertSame('Percentage setup (%/ratio only)', $sheet->getCell('G5')->getValue());
        $this->assertSame('Numerator label (ratio only)', $sheet->getCell('H5')->getValue());
        $this->assertSame('Performance direction', $sheet->getCell('K5')->getValue());
        $this->assertStringContainsString('start on any row', $sheet->getCell('A4')->getValue());
        $this->assertNull($spreadsheet->getSheetByName('Project reference'));
        $this->assertSame(DataValidation::TYPE_LIST, $sheet->getCell('B6')->getDataValidation()->getType());
        $this->assertSame(DataValidation::TYPE_LIST, $sheet->getCell('D6')->getDataValidation()->getType());
        $this->assertSame(DataValidation::TYPE_LIST, $sheet->getCell('G6')->getDataValidation()->getType());
        $this->assertSame('Percentage or ratio only', $sheet->getCell('G6')->getDataValidation()->getPromptTitle());
        $this->assertStringContainsString('Leave this blank for Integer', $sheet->getCell('G6')->getDataValidation()->getPrompt());
        $this->assertSame(DataValidation::TYPE_LIST, $sheet->getCell('J6')->getDataValidation()->getType());
        $this->assertSame('project', $metadata->getCell('B4')->getValue());
        $this->assertSame($firstCentre->id, $metadata->getCell('B5')->getValue());
        $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $spreadsheet->getSheetByName('Lists')->getSheetState());
        $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $metadata->getSheetState());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    public function test_project_rows_are_previewed_then_created_with_generated_defaults_and_one_assignment(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre, $secondCentre] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getSheetByName('Indicators')->fromArray([
            'Children reached', 'Output', 'children', 'Integer', 'Attendance register', null, null, null, null, 'Total across months', 'Higher is better',
        ], null, 'A6');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'project', $firstCentre, '2026-07', $path);
        $this->assertDatabaseCount('indicator_definitions', 0);
        $this->assertSame(['valid' => 1], $batch->summary);

        $this->actingAs($admin)
            ->get(IndicatorDefinitionResource::getUrl('import', ['batch' => $batch->id]))
            ->assertOk()
            ->assertSee('Children reached')
            ->assertSee('Confirm and create 1 indicators');

        $this->actingAs($admin)
            ->post(route('indicator-imports.confirm', $batch), ['confirmation' => '1'])
            ->assertRedirect(IndicatorDefinitionResource::getUrl('index'));

        $indicator = IndicatorDefinition::where('name', 'Children reached')->firstOrFail();
        $this->assertStringStartsWith('IND-EDU-P-1-CHILDREN-REACHED', $indicator->code);
        $this->assertSame('Children reached', $indicator->description);
        $this->assertSame('monthly', $indicator->frequency);
        $this->assertSame('SUM', $indicator->time_aggregation);
        $this->assertSame('NON-AGGREGATABLE', $indicator->centre_aggregation);
        $this->assertSame('higher', $indicator->direction);
        $this->assertFalse($indicator->evidence_required);
        $this->assertTrue($indicator->approval_required);
        $this->assertSame(0, $indicator->min_files);
        $this->assertSame('Attendance register', $indicator->data_sources);
        $this->assertNull($indicator->objective);
        $this->assertNull($indicator->data_collection_method);
        $this->assertNull($indicator->responsible_persons);
        $this->assertSame('2026-07-01', $indicator->effective_date->toDateString());
        $this->assertEqualsCanonicalizing([$firstCentre->id], $indicator->centres()->pluck('centres.id')->all());
        $this->assertNotContains($secondCentre->id, $indicator->centres()->pluck('centres.id')->all());
        $this->assertDatabaseHas('indicator_import_batches', ['id' => $batch->id, 'status' => 'completed']);
        @unlink($path);
    }

    public function test_programme_level_ratio_indicator_is_imported_without_a_project_assignment(): void
    {
        Storage::fake('local');
        [$admin, , $project] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'programme');
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getSheetByName('Indicators')->fromArray([
            'Student retention rate', 'Outcome', '%', 'Percentage', 'Student information system', null, 'Ratio percentage', 'Students retained', 'Students enrolled', 'Ratio from monthly totals', 'Higher is better',
        ], null, 'A6');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'programme', null, '2026-04', $path);
        $this->actingAs($admin)
            ->post(route('indicator-imports.confirm', $batch), ['confirmation' => '1'])
            ->assertRedirect(IndicatorDefinitionResource::getUrl('index'));

        $indicator = IndicatorDefinition::where('name', 'Student retention rate')->firstOrFail();
        $this->assertSame('programme', $indicator->reporting_scope);
        $this->assertSame('ratio_percentage', $indicator->calculation_method);
        $this->assertSame('RATIO', $indicator->time_aggregation);
        $this->assertSame('NON-AGGREGATABLE', $indicator->centre_aggregation);
        $this->assertDatabaseHas('indicator_assignments', [
            'indicator_definition_id' => $indicator->id,
            'centre_id' => null,
            'required' => true,
        ]);
        @unlink($path);
    }

    public function test_invalid_rows_are_previewed_and_cannot_be_partially_imported(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre] = $this->fixture();
        IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'EXISTING-CODE',
            'name' => 'Existing indicator',
            'description' => 'Existing definition.',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'reporting_scope' => 'project',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'active' => true,
        ]);
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getSheetByName('Indicators')->fromArray([
            'Existing indicator', 'Output', 'people', 'Integer', null, null, null, null, null, 'Total across months', 'Higher is better',
        ], null, 'A6');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'project', $firstCentre, '2026-04', $path);
        $this->assertSame('invalid', $batch->rows()->value('status'));
        $this->assertStringContainsString('already exists', $batch->rows()->value('error'));

        $this->actingAs($admin)
            ->get(IndicatorDefinitionResource::getUrl('import', ['batch' => $batch->id]))
            ->assertOk()
            ->assertSee('Nothing has been saved')
            ->assertSee('What to fix')
            ->assertDontSee('Confirm and create');

        $this->actingAs($admin)
            ->post(route('indicator-imports.confirm', $batch), ['confirmation' => '1'])
            ->assertSessionHasErrors('batch');
        $this->assertDatabaseCount('indicator_definitions', 1);
        @unlink($path);
    }

    public function test_invalid_rows_identify_the_exact_column_value_and_correction(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Indicators');
        $sheet->fromArray([
            ['Invalid category', 'Impact', 'children', 'Integer', null, null, null, null, null, 'Total across months', 'Higher is better'],
            ['=CONCAT("Formula"," name")', 'Output', 'children', 'Integer', null, null, null, null, null, 'Total across months', 'Higher is better'],
            ['Invalid percentage aggregation', 'Outcome', '%', 'Percentage', null, null, 'Direct percentage', null, null, 'Total across months', 'Higher is better'],
            ['Missing options', 'Output', 'status', 'Single select', null, null, null, null, null, 'Count each status', 'Match the expected value'],
        ], null, 'A6');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'project', $firstCentre, '2026-04', $path);
        $errors = $batch->rows()->orderBy('row_number')->pluck('error', 'row_number');

        $this->assertStringContainsString('Category: “Impact” is not accepted', $errors[6]);
        $this->assertStringContainsString('Indicator name: formulas are not allowed', $errors[7]);
        $this->assertStringContainsString('Across months: “Total across months” is not supported for Percentage', $errors[8]);
        $this->assertStringContainsString('Accepted options (use |): add at least one option for Single select', $errors[9]);
        $this->assertDatabaseCount('indicator_definitions', 0);
        @unlink($path);
    }

    public function test_a_user_can_start_on_the_second_data_row_and_blank_rows_are_ignored(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Indicators');
        $sheet->setCellValue('A6', "\u{00A0}\u{200B}");
        $sheet->fromArray([
            'Children attending', 'Output', 'children', 'Integer', null, null, null, null, null, 'Total across months', 'Higher is better',
        ], null, 'A7');
        $sheet->setCellValue('C8', "\u{200B}");
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'project', $firstCentre, '2026-04', $path);

        $this->assertSame(['valid' => 1], $batch->summary);
        $this->assertSame([7], $batch->rows()->pluck('row_number')->all());
        @unlink($path);
    }

    public function test_an_already_downloaded_template_with_previous_headers_can_still_be_uploaded(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Indicators');
        $sheet->fromArray([
            'Accepted options (use |)', 'Percentage entry', 'Numerator label', 'Denominator label',
        ], null, 'F5');
        $sheet->fromArray([
            'Children attending', 'Output', 'children', 'Integer', null, null, null, null, null, 'Total across months', 'Higher is better',
        ], null, 'A7');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'project', $firstCentre, '2026-04', $path);

        $this->assertSame(['valid' => 1], $batch->summary);
        $this->assertSame([7], $batch->rows()->pluck('row_number')->all());
        @unlink($path);
    }

    public function test_preview_explains_leftover_cells_in_an_otherwise_empty_row(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Indicators');
        $sheet->setCellValue('J8', 'Total across months');
        $sheet->setCellValue('K8', 'Higher is better');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'project', $firstCentre, '2026-04', $path);

        $this->actingAs($admin)
            ->get(IndicatorDefinitionResource::getUrl('import', ['batch' => $batch->id]))
            ->assertOk()
            ->assertSee('Incomplete row')
            ->assertSee('Excel row 8 is not blank')
            ->assertSee('J8 (Across months)')
            ->assertSee('K8 (Performance direction)')
            ->assertSee('clear those cells so the row is ignored');
        @unlink($path);
    }

    public function test_integer_percentage_setup_error_names_the_exact_cell_to_clear(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getSheetByName('Indicators')->fromArray([
            'Children attending', 'Output', 'children', 'Integer', null, null, 'Direct percentage', null, null, 'Total across months', 'Higher is better',
        ], null, 'A7');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $batch = $this->upload($admin, $project, 'project', $firstCentre, '2026-04', $path);

        $this->actingAs($admin)
            ->get(IndicatorDefinitionResource::getUrl('import', ['batch' => $batch->id]))
            ->assertOk()
            ->assertSee('Excel cell G7 (Percentage entry)')
            ->assertSee('leave this blank when Input type is Integer');
        @unlink($path);
    }

    public function test_project_template_cannot_be_uploaded_for_another_project_card(): void
    {
        Storage::fake('local');
        [$admin, , $project, $firstCentre, $secondCentre] = $this->fixture();
        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getSheetByName('Indicators')->fromArray([
            'Children attending', 'Output', 'children', 'Integer', null, null, null, null, null, 'Total across months', 'Higher is better',
        ], null, 'A6');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile($path, 'indicators.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($admin)->post(route('indicator-imports.upload'), [
            'project_id' => $project->id,
            'scope' => 'project',
            'centre_id' => $secondCentre->id,
            'effective_from' => '2026-04',
            'file' => $upload,
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('indicator_import_batches', 0);
        @unlink($path);
    }

    public function test_non_admin_users_cannot_download_or_upload_indicator_templates(): void
    {
        [$admin, $programmeLead, $project, $firstCentre] = $this->fixture();

        $this->actingAs($programmeLead)
            ->get(route('indicator-imports.template.select', [
                'project_id' => $project->id,
                'scope' => 'project',
                'centre_id' => $firstCentre->id,
            ]))
            ->assertForbidden();
        $this->actingAs($programmeLead)
            ->post(route('indicator-imports.upload'), [
                'project_id' => $project->id,
                'scope' => 'project',
                'centre_id' => $firstCentre->id,
                'effective_from' => '2026-04',
            ])
            ->assertForbidden();

        $path = $this->templatePath($admin, $project, 'project', $firstCentre);
        @unlink($path);
    }

    /** @return array{User, User, Project, Centre, Centre} */
    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $programmeLead = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Education', 'description' => 'Test programme']);
        $project = Project::create([
            'programme_id' => $programme->id,
            'code' => 'EDU',
            'name' => 'Education',
            'description' => 'Test programme reporting record',
            'active' => true,
        ]);
        $firstCentre = Centre::create(['code' => 'P-1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun', 'active' => true]);
        $secondCentre = Centre::create(['code' => 'P-2', 'name' => 'Project Two', 'type' => 'Education Centre', 'region' => 'Dehradun', 'active' => true]);
        $project->centres()->attach([$firstCentre->id, $secondCentre->id]);

        return [$admin, $programmeLead, $project, $firstCentre, $secondCentre];
    }

    private function templatePath(User $admin, Project $project, string $reportingScope, ?Centre $centre = null): string
    {
        $response = $this->actingAs($admin)->get(route('indicator-imports.template.select', array_filter([
            'project_id' => $project->id,
            'scope' => $reportingScope,
            'centre_id' => $centre?->id,
        ], fn (mixed $value): bool => $value !== null)));
        $response->assertOk();

        return $this->saveResponse($response->streamedContent());
    }

    private function upload(
        User $admin,
        Project $project,
        string $reportingScope,
        ?Centre $centre,
        string $effectiveFrom,
        string $path,
    ): IndicatorImportBatch {
        $upload = new UploadedFile($path, 'indicators.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $response = $this->actingAs($admin)->post(route('indicator-imports.upload'), array_filter([
            'project_id' => $project->id,
            'scope' => $reportingScope,
            'centre_id' => $centre?->id,
            'effective_from' => $effectiveFrom,
            'file' => $upload,
        ], fn (mixed $value): bool => $value !== null));
        $batch = IndicatorImportBatch::latest('id')->firstOrFail();
        $response->assertRedirect(IndicatorDefinitionResource::getUrl('import', ['batch' => $batch->id]));

        return $batch;
    }

    private function saveResponse(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aasraa-indicators-');
        file_put_contents($path, $content);

        return $path;
    }
}
