<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Services\TataIndicatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TataIndicatorCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Project> */
    private array $projects;

    protected function setUp(): void
    {
        parent::setUp();

        $streetSmartProgramme = Programme::create([
            'name' => 'Street Smart',
            'description' => 'Street Smart',
        ]);
        $mainstreamingProgramme = Programme::create([
            'name' => 'Mainstreaming / SQEP',
            'description' => 'Mainstreaming / SQEP',
        ]);
        $this->projects = [
            'PRG-01' => Project::create([
                'programme_id' => $streetSmartProgramme->id,
                'code' => 'PRG-01',
                'name' => 'Street Smart',
                'description' => 'Street Smart',
                'active' => true,
            ]),
            'PRG-02' => Project::create([
                'programme_id' => $mainstreamingProgramme->id,
                'code' => 'PRG-02',
                'name' => 'Mainstreaming / SQEP',
                'description' => 'Mainstreaming / SQEP',
                'active' => true,
            ]),
        ];

        foreach (TataIndicatorService::CENTRE_CODES as $group => $codes) {
            $projectCode = $group === 'SS' ? 'PRG-01' : 'PRG-02';
            foreach ($codes as $code) {
                $centre = Centre::create([
                    'code' => $code,
                    'name' => $code,
                    'type' => 'Education Centre',
                    'region' => 'Dehradun',
                    'active' => true,
                ]);
                $this->projects[$projectCode]->centres()->attach($centre, [
                    'is_demo' => false,
                    'provenance' => 'test',
                ]);
            }
        }
    }

    public function test_it_restores_the_monthly_catalogue_without_targets_or_achievements(): void
    {
        $service = app(TataIndicatorService::class);
        $statistics = $service->restore($service->readWorkbook($this->workbookPath()));

        $this->assertSame(13, $statistics['created_indicators']);
        $this->assertSame(41, $statistics['created_assignments']);
        $this->assertSame(13, IndicatorDefinition::count());
        $this->assertSame(41, IndicatorAssignment::count());
        $this->assertSame(0, IndicatorTarget::count());
        $this->assertSame(0, IndicatorEntry::count());
        $this->assertSame(13, IndicatorDefinition::query()->where('frequency', 'monthly')->count());
        $this->assertSame(4, IndicatorDefinition::query()->where('reporting_scope', 'programme')->count());
        $this->assertSame(4, IndicatorAssignment::query()->whereNull('centre_id')->count());
        $this->assertSame(5, IndicatorDefinition::query()->where('project_id', $this->projects['PRG-01']->id)->count());
        $this->assertSame(8, IndicatorDefinition::query()->where('project_id', $this->projects['PRG-02']->id)->count());
        $this->assertDatabaseHas('indicator_definitions', [
            'code' => 'KPI-SQEP-FLN',
            'name' => '% of students of classes 3 - 5 in 6 government schools (SQEP) achieving FLN competencies in Hindi & Maths',
            'data_type' => 'percentage',
            'time_aggregation' => 'LATEST SNAPSHOT',
        ]);
    }

    public function test_restoration_is_idempotent_and_preserves_existing_indicator_data(): void
    {
        $service = app(TataIndicatorService::class);
        $names = $service->readWorkbook($this->workbookPath());
        $service->restore($names);

        $indicator = IndicatorDefinition::query()->where('code', 'TATA-1')->firstOrFail();
        $assignment = $indicator->assignments()->firstOrFail();
        IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-04-01',
            'period_end' => '2026-04-30',
            'period_type' => 'monthly',
            'target_type' => 'single',
            'value' => 20,
            'active' => true,
            'is_demo' => false,
        ]);
        IndicatorEntry::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-04-01',
            'status' => 'DRAFT',
            'is_demo' => false,
        ]);

        $secondRun = $service->restore($names);

        $this->assertSame(0, $secondRun['created_indicators']);
        $this->assertSame(13, $secondRun['existing_indicators']);
        $this->assertSame(0, $secondRun['created_assignments']);
        $this->assertSame(41, $secondRun['existing_assignments']);
        $this->assertSame(13, IndicatorDefinition::count());
        $this->assertSame(41, IndicatorAssignment::count());
        $this->assertSame(1, IndicatorTarget::count());
        $this->assertSame(1, IndicatorEntry::count());
    }

    public function test_command_previews_before_applying_the_catalogue(): void
    {
        $path = $this->workbookPath();

        $this->artisan('impact:restore-tata-indicators', ['file' => $path])
            ->expectsOutputToContain('Preview complete. No data was changed.')
            ->assertSuccessful();
        $this->assertSame(0, IndicatorDefinition::count());

        $this->artisan('impact:restore-tata-indicators', ['file' => $path, '--apply' => true])
            ->expectsOutputToContain('Tata indicators restored.')
            ->assertSuccessful();
        $this->assertSame(13, IndicatorDefinition::count());
    }

    private function workbookPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aasraa-tata-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Results Framework');
        $sheet->setCellValue('D2', 'Year 2 Target (FY 2026-2027)');

        $names = [
            3 => 'Number of unique Out of School Children enrolled in Community Based Learning Centres',
            4 => 'Number of orientation sessions conducted with parents at Community Based Learning Centre',
            5 => 'Number of parent-teacher meeting (Once in 2 months) conducted at Community Based Learning Centre',
            6 => 'Number of unique Out of School Children enrolled in NIOS-OBE Centres',
            7 => 'Number of orientation sessions conducted with parents at NIOS-OBE Centres',
            8 => 'Number of parent-teacher meeting (Once in 2 months) conducted at NIOS-OBE Centres',
            9 => 'No. of government schools (SQEP) wherein repair and maintenance work is carried out',
            12 => 'Number of Out of School Children successfully mainstream from CBLC into government schools & NIOS-OBE',
            14 => 'Number of students enrolled in the government schools (SQEP) where repair and maintenance work carried out',
            17 => 'Retention rate of OoSC mainstreamed into government schools',
            18 => 'Retention rate of OoSC mainstreamed into NIOS-OBE',
            19 => 'Decline in the dropout rates in 6 government schools (SQEP)',
            20 => '% of students of classes 3 - 5 in 6 government schools (SQEP) achieving FLN competencies in Hindi & Maths',
        ];

        foreach ($names as $row => $name) {
            $sheet->setCellValue("B{$row}", $name);
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
