<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use LogicException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TataIndicatorService
{
    /** @var array<string, list<string>> */
    public const CENTRE_CODES = [
        'SS' => ['ORG-41', 'ORG-68', 'ORG-75', 'ORG-47'],
        'NIOS' => ['ORG-77', 'ORG-67', 'ORG-74'],
        'SQEP' => ['ORG-32', 'ORG-43', 'ORG-97', 'ORG-40', 'ORG-36', 'ORG-34'],
    ];

    /**
     * @return array<int, array{code: string, group: string, project_code: string, category: string, unit: string, data_type: string, reporting_scope: string, time_aggregation: string}>
     */
    public function specifications(): array
    {
        return [
            3 => $this->projectSpecification('TATA-1', 'SS', 'PRG-01', 'Activity', 'people'),
            4 => $this->projectSpecification('KPI-CBLC-ORIENTATION', 'SS', 'PRG-01', 'Activity', 'sessions'),
            5 => $this->projectSpecification('KPI-CBLC-PTM', 'SS', 'PRG-01', 'Activity', 'meetings'),
            6 => $this->projectSpecification('KPI-NIOS-ENROLMENT', 'NIOS', 'PRG-02', 'Activity', 'people'),
            7 => $this->projectSpecification('KPI-NIOS-ORIENTATION', 'NIOS', 'PRG-02', 'Activity', 'sessions'),
            8 => $this->projectSpecification('KPI-NIOS-PTM', 'NIOS', 'PRG-02', 'Activity', 'meetings'),
            9 => $this->projectSpecification('KPI-SQEP-REPAIRS', 'SQEP', 'PRG-02', 'Activity', 'schools'),
            12 => $this->projectSpecification('KPI-CBLC-MAINSTREAM', 'SS', 'PRG-01', 'Output', 'people'),
            14 => $this->projectSpecification('KPI-SQEP-ENROLMENT', 'SQEP', 'PRG-02', 'Output', 'students'),
            17 => $this->programmeSpecification('KPI-SS-RETENTION', 'SS', 'PRG-01'),
            18 => $this->programmeSpecification('KPI-NIOS-RETENTION', 'NIOS', 'PRG-02'),
            19 => $this->programmeSpecification('KPI-SQEP-DROPOUT', 'SQEP', 'PRG-02'),
            20 => $this->programmeSpecification('KPI-SQEP-FLN', 'SQEP', 'PRG-02'),
        ];
    }

    /** @return array<int, string> */
    public function catalogueNames(): array
    {
        return [
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
    }

    /** @return array<int, string> */
    public function readWorkbook(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new LogicException("The Tata KPI workbook is not readable: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);

        try {
            $sheet = $workbook->getSheetByName('Results Framework');
            if ($sheet === null || ! str_contains((string) $sheet->getCell('D2')->getValue(), '2026-2027')) {
                throw new LogicException('Expected the FY 2026-27 Results Framework worksheet.');
            }

            $names = [];
            foreach (array_keys($this->specifications()) as $row) {
                $name = trim((string) $sheet->getCell("B{$row}")->getValue());
                if ($name === '') {
                    throw new LogicException("Missing indicator name in Results Framework!B{$row}.");
                }

                $names[$row] = $name;
            }

            return $names;
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    /**
     * @param  array<int, string>  $names
     * @return array{created_indicators: int, existing_indicators: int, created_assignments: int, existing_assignments: int}
     */
    public function restore(array $names): array
    {
        return DB::transaction(function () use ($names): array {
            $projects = Project::query()
                ->whereIn('code', ['PRG-01', 'PRG-02'])
                ->get()
                ->keyBy('code');

            if ($projects->count() !== 2) {
                throw new LogicException('Street Smart (PRG-01) and Mainstreaming / SQEP (PRG-02) must exist before restoring the Tata indicators.');
            }

            $allCentreCodes = array_merge(...array_values(self::CENTRE_CODES));
            $centres = Centre::query()
                ->whereIn('code', $allCentreCodes)
                ->with('projects:id,code')
                ->get()
                ->keyBy('code');

            if ($centres->count() !== count($allCentreCodes)) {
                $missingCodes = array_values(array_diff($allCentreCodes, $centres->keys()->all()));

                throw new LogicException('Missing Tata reporting centres: '.implode(', ', $missingCodes));
            }

            $this->validateCentreMemberships($centres->all());

            $statistics = [
                'created_indicators' => 0,
                'existing_indicators' => 0,
                'created_assignments' => 0,
                'existing_assignments' => 0,
            ];

            foreach ($this->specifications() as $row => $specification) {
                if (! isset($names[$row])) {
                    throw new LogicException("Missing indicator name for Results Framework row {$row}.");
                }

                $indicator = IndicatorDefinition::query()->where('code', $specification['code'])->first();
                if ($indicator !== null) {
                    $this->validateExistingIndicator($indicator, $specification['project_code']);
                    $statistics['existing_indicators']++;
                } else {
                    $indicator = IndicatorDefinition::create($this->definitionAttributes(
                        $projects[$specification['project_code']]->id,
                        $names[$row],
                        $specification,
                    ));
                    $statistics['created_indicators']++;
                }

                $assignmentCentreIds = $specification['reporting_scope'] === 'programme'
                    ? [null]
                    : array_map(
                        fn (string $code): int => $centres[$code]->id,
                        self::CENTRE_CODES[$specification['group']],
                    );

                foreach ($assignmentCentreIds as $centreId) {
                    $assignment = IndicatorAssignment::query()
                        ->where('indicator_definition_id', $indicator->id)
                        ->where('centre_id', $centreId)
                        ->first();

                    if ($assignment !== null) {
                        $statistics['existing_assignments']++;

                        continue;
                    }

                    IndicatorAssignment::create([
                        'indicator_definition_id' => $indicator->id,
                        'centre_id' => $centreId,
                        'required' => true,
                        'is_demo' => false,
                    ]);
                    $statistics['created_assignments']++;
                }
            }

            return $statistics;
        });
    }

    /** @param array<string, Centre> $centres */
    private function validateCentreMemberships(array $centres): void
    {
        $expectedProjects = ['SS' => 'PRG-01', 'NIOS' => 'PRG-02', 'SQEP' => 'PRG-02'];

        foreach (self::CENTRE_CODES as $group => $codes) {
            foreach ($codes as $code) {
                if (! $centres[$code]->projects->contains('code', $expectedProjects[$group])) {
                    throw new LogicException("Centre {$code} is not assigned to the expected project {$expectedProjects[$group]}.");
                }
            }
        }
    }

    private function validateExistingIndicator(IndicatorDefinition $indicator, string $projectCode): void
    {
        if ($indicator->project?->code !== $projectCode) {
            throw new LogicException("Indicator {$indicator->code} already exists under a different programme. No data was changed.");
        }
    }

    /**
     * @param  array{code: string, category: string, unit: string, data_type: string, reporting_scope: string, time_aggregation: string}  $specification
     * @return array<string, mixed>
     */
    private function definitionAttributes(int $projectId, string $name, array $specification): array
    {
        $isProgrammeIndicator = $specification['reporting_scope'] === 'programme';

        return app(IndicatorService::class)->prepareAndValidate([
            'project_id' => $projectId,
            'code' => $specification['code'],
            'name' => $name,
            'description' => $isProgrammeIndicator
                ? 'Report one programme-level percentage for each applicable month. This result is not duplicated across projects.'
                : 'Report the result for each assigned project in the applicable month. Monthly results are combined in reports using the selected aggregation method.',
            'category' => $specification['category'],
            'unit' => $specification['unit'],
            'data_type' => $specification['data_type'],
            'value_required' => true,
            'calculation_method' => $isProgrammeIndicator ? 'direct_percentage' : null,
            'frequency' => 'monthly',
            'reporting_scope' => $specification['reporting_scope'],
            'time_aggregation' => $specification['time_aggregation'],
            'centre_aggregation' => $isProgrammeIndicator ? 'NON-AGGREGATABLE' : 'SUM',
            'direction' => 'higher',
            'target_tracking' => true,
            'min_value' => 0,
            'max_value' => $isProgrammeIndicator ? 100 : null,
            'precision' => $isProgrammeIndicator ? 1 : 0,
            'max_length' => 2000,
            'options' => null,
            'effective_date' => '2026-04-01',
            'evidence_required' => false,
            'allowed_extensions' => config('impact.evidence_extensions'),
            'max_file_kb' => 5120,
            'min_files' => 0,
            'max_files' => 5,
            'evidence_review_required' => false,
            'approval_required' => true,
            'active' => true,
            'is_demo' => false,
        ]);
    }

    /**
     * @return array{code: string, group: string, project_code: string, category: string, unit: string, data_type: string, reporting_scope: string, time_aggregation: string}
     */
    private function projectSpecification(string $code, string $group, string $projectCode, string $category, string $unit): array
    {
        return [
            'code' => $code,
            'group' => $group,
            'project_code' => $projectCode,
            'category' => $category,
            'unit' => $unit,
            'data_type' => 'integer',
            'reporting_scope' => 'project',
            'time_aggregation' => 'SUM',
        ];
    }

    /**
     * @return array{code: string, group: string, project_code: string, category: string, unit: string, data_type: string, reporting_scope: string, time_aggregation: string}
     */
    private function programmeSpecification(string $code, string $group, string $projectCode): array
    {
        return [
            'code' => $code,
            'group' => $group,
            'project_code' => $projectCode,
            'category' => 'Outcome',
            'unit' => '%',
            'data_type' => 'percentage',
            'reporting_scope' => 'programme',
            'time_aggregation' => 'LATEST SNAPSHOT',
        ];
    }
}
