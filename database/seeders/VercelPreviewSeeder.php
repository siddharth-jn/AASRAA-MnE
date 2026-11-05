<?php

namespace Database\Seeders;

use App\Models\Centre;
use App\Models\CentreDonor;
use App\Models\Donor;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorDefinitionVersion;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\TataIndicatorService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VercelPreviewSeeder extends Seeder
{
    public function run(TataIndicatorService $tataIndicators): void
    {
        if ($this->databaseAlreadyHasUsers()) {
            return;
        }

        DB::transaction(function () use ($tataIndicators): void {
            $this->createMissingRoleAccounts();
            $this->createPreviewProgrammes();
            $this->createPreviewCentres();
            $tataCentres = $this->createTataCentres();
            $this->assignPreviewUsers();
            $this->assignTataDonor($tataCentres);
            $this->createStandardDemoReportingData();

            $tataIndicators->restore($tataIndicators->catalogueNames());
            $indicatorCodes = collect($tataIndicators->specifications())->pluck('code');
            $indicatorIds = IndicatorDefinition::query()->whereIn('code', $indicatorCodes)->pluck('id');

            IndicatorDefinition::query()->whereIn('id', $indicatorIds)->update(['is_demo' => true]);
            IndicatorDefinitionVersion::query()->whereIn('indicator_definition_id', $indicatorIds)->update(['is_demo' => true]);
            IndicatorAssignment::query()->whereIn('indicator_definition_id', $indicatorIds)->update(['is_demo' => true]);

            $this->createTataTargets($indicatorIds->all());
        });
    }

    private function databaseAlreadyHasUsers(): bool
    {
        return User::query()->exists();
    }

    private function createMissingRoleAccounts(): void
    {
        $password = config('impact.demo_password') ?: 'AasraImpact!2026';
        $accounts = [
            ['Sandeep Preview Administrator', 'sandeep@aasra.demo', 'ADMIN'],
            ['Assistant Programme Lead', 'assistant@aasra.demo', 'ASSISTANT PROGRAM LEAD'],
            ['Programme Lead', 'lead@aasra.demo', 'PROGRAM LEAD'],
            ['Senior Programme Lead', 'senior@aasra.demo', 'SENIOR PROGRAM LEAD'],
            ['M&E Head', 'mne@aasra.demo', 'M&E HEAD'],
            ['Chief Executive Officer', 'ceo@aasra.demo', 'CEO'],
            ['Chief Financial Officer', 'cfo@aasra.demo', 'CFO'],
            ['Centre Coordinator', 'coordinator@aasra.demo', 'CENTRE COORDINATOR'],
            ['Management Viewer', 'viewer@aasra.demo', 'VIEWER'],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => $password,
                    'role' => $role,
                    'active' => true,
                    'is_demo' => true,
                ],
            );
        }
    }

    private function createPreviewProgrammes(): void
    {
        $programmeLeadId = User::query()->where('email', 'lead@aasra.demo')->value('id');
        $rows = [
            ['Street Smart', 'Prepares out-of-school children for mainstream education through foundational learning.', 'street-smart'],
            ['Mainstreaming / SQEP', 'Supports formal education access and learning quality in partner schools.', 'mainstreaming'],
            ['Wings of Doon', 'Provides after-school education, mentoring, and academic reinforcement.', 'wings-of-doon'],
            ["Girls' Education / TITAN KANYA+", 'Supports girls through education, life skills, career guidance, and retention.', 'girls-education'],
            ['Higher Education', 'Supports access to diploma, certification, degree, and professional education.', 'higher-education-program'],
            ['SkillAbility', 'Provides vocational, employability, and enterprise training to underserved youth.', 'skillability'],
            ['Special Needs Centre', 'Provides individualized educational and developmental support.', 'special-needs-center'],
            ['Shelter Homes', 'Provides safe residential care, education, nutrition, healthcare, and holistic support.', 'shelter-homes'],
            ['Healthcare', 'Supports access to assessments, preventive care, referrals, and health education.', 'healthcare'],
            ['Nutrition', 'Provides meals, snacks, food preparation, and distribution.', 'nutrition'],
            ['Outreach', 'Connects underserved children and youth with education and skills opportunities.', 'outreach'],
        ];

        foreach ($rows as $index => [$name, $description, $slug]) {
            $programme = Programme::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => $description,
                    'source_url' => "https://aasraatrust.org/programmes/{$slug}/",
                    'is_demo' => true,
                ],
            );
            Project::query()->updateOrCreate(
                ['code' => sprintf('PRG-%02d', $index + 1)],
                [
                    'programme_id' => $programme->id,
                    'name' => $name,
                    'description' => $description,
                    'lead_id' => $programmeLeadId,
                    'active' => true,
                    'is_demo' => true,
                ],
            );
        }
    }

    private function createPreviewCentres(): void
    {
        $centreRows = [
            ['SS-ISBT', 'Street Smart, ISBT', 'Education Centre', 'Dehradun', 110, 'https://aasraatrust.org/'],
            ['MLC-KB', 'Mobile Learning Centre, Kabadi Bazar', 'Mobile Learning Unit', 'Dehradun', 75, 'https://aasraatrust.org/'],
            ['EC-PN', 'Education Cart, Prem Nagar', 'Community Location', 'Dehradun', 45, 'https://aasraatrust.org/'],
            ['GPS-C2', 'Government Primary School, Chukkhuwala 2', 'Partner School', 'Dehradun', 180, 'https://aasraatrust.org/'],
            ['GPS-AR', 'Government Primary School, Araghar', 'Partner School', 'Dehradun', 160, 'https://aasraatrust.org/'],
            ['GUPS-RC', 'Government Upper Primary School, Rest Camp', 'Partner School', 'Dehradun', 210, 'https://aasraatrust.org/'],
            ['NIOS-GG', 'NIOS Centre, Govindgarh', 'Education Centre', 'Dehradun', 120, 'https://aasraatrust.org/'],
            ['WOD-NH', 'Wings of Doon, New Haven, Kanwali Road', 'Education Centre', 'Dehradun', 130, 'https://aasraatrust.org/'],
            ['WOD-SDIC', 'Wings of Doon, Sanatan Dharm Inter College, Bannu, Racecourse', 'Partner School', 'Dehradun', 165, 'https://aasraatrust.org/'],
            ['SNC-IN', 'Special Needs Centre, Indira Nagar', 'Education Centre', 'Dehradun', 45, 'https://aasraatrust.org/'],
            ['SA-RP', 'SkillAbility Centre, Raipur', 'Training Centre', 'Dehradun', 140, 'https://aasraatrust.org/'],
            ['GGIC-DI', 'GGIC Dineshpur', 'Partner School', 'Udham Singh Nagar', 320, 'https://aasraatrust.org/'],
            ['GPS-TH', 'Government Primary School, Thangarh', 'Partner School', 'Pauri Garhwal', 95, 'https://aasraatrust.org/'],
            ['BSH-AR', 'Boys’ Shelter Home, Araghar', 'Shelter Home', 'Dehradun', 70, 'https://aasraatrust.org/'],
            ['GSH-RR', 'Girls’ Shelter Home, Rajpur Road', 'Shelter Home', 'Dehradun', 65, 'https://aasraatrust.org/'],
            ['GGIC-SU', 'GGIC Sultanpur', 'Partner School', 'Udham Singh Nagar', 280, 'https://aasraatrust.org/'],
            ['GGIC-MG', 'GGIC Mahuakhera Ganj', 'Partner School', 'Udham Singh Nagar', 300, 'https://aasraatrust.org/'],
            ['GGIC-GA', 'GGIC Gadarpur', 'Partner School', 'Udham Singh Nagar', 290, 'https://aasraatrust.org/'],
            ['REG-DDN', 'Higher Education — Dehradun', 'Programme Reporting Region', 'Dehradun', null, 'https://aasraatrust.org/programmes/higher-education-program/'],
            ['REG-USN', 'Higher Education — Udham Singh Nagar', 'Programme Reporting Region', 'Udham Singh Nagar', null, 'https://aasraatrust.org/programmes/higher-education-program/'],
        ];
        $projectCentreIndexes = [
            0 => [0, 1, 2],
            1 => [3, 4, 5, 6, 12],
            2 => [7, 8],
            3 => [11, 15, 16, 17],
            4 => [18, 19],
            5 => [10],
            6 => [9],
            7 => [13, 14],
            8 => [0, 1, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17],
            9 => [0, 1, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17],
            10 => [0, 1, 2, 3, 4, 5, 10, 11, 12, 15, 16, 17],
        ];
        $centres = collect($centreRows)->map(fn (array $row): Centre => Centre::query()->updateOrCreate(
            ['code' => $row[0]],
            [
                'name' => $row[1],
                'type' => $row[2],
                'region' => $row[3],
                'capacity' => $row[4],
                'source_url' => $row[5],
                'provenance' => 'public name; synthetic reporting configuration',
                'active' => true,
                'is_demo' => true,
            ],
        ));
        $projects = Project::query()
            ->whereIn('code', collect(range(1, 11))->map(fn (int $index): string => sprintf('PRG-%02d', $index)))
            ->get()
            ->keyBy('code');

        foreach ($projectCentreIndexes as $projectIndex => $centreIndexes) {
            $project = $projects[sprintf('PRG-%02d', $projectIndex + 1)];
            $project->centres()->syncWithoutDetaching(
                collect($centreIndexes)->mapWithKeys(fn (int $centreIndex): array => [
                    $centres[$centreIndex]->id => [
                        'is_demo' => true,
                        'provenance' => 'synthetic programme-centre assignment',
                    ],
                ])->all(),
            );
        }
    }

    private function createStandardDemoReportingData(): void
    {
        $now = now();
        $programmeLeadId = User::query()->where('email', 'lead@aasra.demo')->value('id');
        $seniorProgrammeLeadId = User::query()->where('email', 'senior@aasra.demo')->value('id');
        $projects = Project::query()->whereIn('code', collect(range(1, 11))->map(fn (int $index): string => sprintf('PRG-%02d', $index)))->get()->keyBy('code');
        $centres = Centre::query()->whereIn('code', collect($this->standardProjectCentreCodes())->flatten())->get()->keyBy('code');
        $templates = [
            ['Active staff', 'Input', 'people', 'LATEST SNAPSHOT', 'SUM'],
            ['Current participants', 'Input', 'people', 'LATEST SNAPSHOT', 'SUM'],
            ['Sessions conducted', 'Activity', 'sessions', 'SUM', 'SUM'],
            ['Engagement activities', 'Activity', 'activities', 'SUM', 'SUM'],
            ['Participants completing support', 'Output', 'people', 'SUM', 'SUM'],
            ['Performance rate', 'Outcome', '%', 'RATIO', 'RATIO'],
        ];
        $definitionRows = [];

        foreach ($projects as $projectIndex => $project) {
            $numericProjectIndex = (int) substr($projectIndex, 4) - 1;
            foreach ($templates as $indicatorIndex => $template) {
                $name = match ([$numericProjectIndex, $indicatorIndex]) {
                    [0, 2] => 'Literacy Sessions Conducted',
                    [5, 2] => 'Vocational Training Sessions',
                    [7, 1] => 'Current Occupancy',
                    [9, 1] => 'Current Beneficiary Strength',
                    [9, 2] => 'Meals Distributed',
                    [9, 5] => 'Meal Coverage Rate',
                    default => $template[0],
                };
                $isRatio = $template[3] === 'RATIO';
                $definitionRows[] = [
                    'project_id' => $project->id,
                    'code' => sprintf('P%02d-I%02d', $numericProjectIndex + 1, $indicatorIndex + 1),
                    'name' => $name,
                    'description' => "Demo {$template[1]} indicator for {$project->name}.",
                    'category' => $template[1],
                    'unit' => $template[2],
                    'data_type' => $isRatio ? 'ratio' : 'integer',
                    'frequency' => 'monthly',
                    'reporting_scope' => 'project',
                    'time_aggregation' => $template[3],
                    'centre_aggregation' => $template[4],
                    'direction' => 'higher',
                    'min_value' => 0,
                    'max_value' => $isRatio ? 100 : null,
                    'precision' => $isRatio ? 1 : 0,
                    'evidence_required' => $indicatorIndex === 2,
                    'allowed_extensions' => json_encode(['pdf', 'jpg', 'jpeg', 'png']),
                    'effective_date' => '2026-04-01',
                    'active' => true,
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->upsertInChunks('indicator_definitions', $definitionRows, ['code'], [
            'project_id', 'name', 'description', 'category', 'unit', 'data_type', 'frequency', 'reporting_scope',
            'time_aggregation', 'centre_aggregation', 'direction', 'min_value', 'max_value', 'precision',
            'evidence_required', 'allowed_extensions', 'effective_date', 'active', 'is_demo', 'updated_at',
        ]);

        $definitions = IndicatorDefinition::query()->where('code', 'like', 'P%-I%')->get()->keyBy('code');
        $this->upsertInChunks(
            'indicator_definition_versions',
            $definitions->map(fn (IndicatorDefinition $indicator): array => [
                'indicator_definition_id' => $indicator->id,
                'version' => 1,
                'definition' => json_encode($indicator->attributesToArray()),
                'is_demo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['indicator_definition_id', 'version'],
            ['definition', 'is_demo', 'updated_at'],
        );

        $assignmentRows = [];
        foreach ($this->standardProjectCentreCodes() as $projectIndex => $centreCodes) {
            foreach ($templates as $indicatorIndex => $template) {
                $indicator = $definitions[sprintf('P%02d-I%02d', $projectIndex + 1, $indicatorIndex + 1)];
                foreach ($centreCodes as $centreCode) {
                    $assignmentRows[] = [
                        'indicator_definition_id' => $indicator->id,
                        'centre_id' => $centres[$centreCode]->id,
                        'required' => true,
                        'is_demo' => true,
                    ];
                }
            }
        }
        $this->upsertInChunks(
            'indicator_assignments',
            $assignmentRows,
            ['indicator_definition_id', 'centre_id'],
            ['required', 'is_demo'],
        );

        $assignments = IndicatorAssignment::query()
            ->whereIn('indicator_definition_id', $definitions->pluck('id'))
            ->with(['indicator:id,code', 'centre:id,code'])
            ->get()
            ->keyBy(fn (IndicatorAssignment $assignment): string => $assignment->indicator->code.'|'.$assignment->centre->code);
        $versions = IndicatorDefinitionVersion::query()
            ->whereIn('indicator_definition_id', $definitions->pluck('id'))
            ->where('version', 1)
            ->get()
            ->keyBy('indicator_definition_id');
        $periods = collect(range(4, 9))->map(fn (int $month): string => sprintf('2026-%02d-01', $month));
        $targetRows = [];
        $entrySpecifications = [];

        foreach ($this->standardProjectCentreCodes() as $projectIndex => $centreCodes) {
            foreach ($centreCodes as $centreOffset => $centreCode) {
                foreach ($templates as $indicatorIndex => $template) {
                    $indicator = $definitions[sprintf('P%02d-I%02d', $projectIndex + 1, $indicatorIndex + 1)];
                    $assignment = $assignments[$indicator->code.'|'.$centreCode];
                    foreach ($periods as $periodOffset => $period) {
                        $base = 8 + (($projectIndex + 1) * 3) + (($centreOffset + 1) * 4) + $indicatorIndex * 5;
                        $target = $indicatorIndex === 5 ? 82 : $base + 5;
                        $targetRows[] = [
                            'indicator_assignment_id' => $assignment->id,
                            'period' => $period,
                            'period_type' => 'monthly',
                            'period_end' => date('Y-m-t', strtotime($period)),
                            'target_type' => 'numeric',
                            'value' => $target,
                            'active' => true,
                            'is_demo' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        if (($projectIndex + $centreOffset + $indicatorIndex + $periodOffset) % 19 === 0) {
                            continue;
                        }

                        $selector = ($projectIndex * 17 + $centreOffset * 7 + $indicatorIndex * 3 + $periodOffset) % 23;
                        $status = $selector === 0 ? 'SUBMITTED' : ($selector === 1 ? 'RETURNED FOR CORRECTION' : ($selector === 2 ? 'REJECTED' : 'APPROVED'));
                        $numerator = $indicatorIndex === 5 ? min($target + 10, 68 + (($centreOffset + $periodOffset + $projectIndex) % 24)) : null;
                        $entrySpecifications[] = [
                            'assignment_id' => $assignment->id,
                            'indicator_definition_version_id' => $versions[$indicator->id]->id,
                            'period' => $period,
                            'status' => $status,
                            'numeric_value' => $indicatorIndex === 5 ? $numerator : max(0, $base + (($periodOffset * 2 + $centreOffset) % 9) - 3),
                            'numerator' => $numerator,
                            'denominator' => $indicatorIndex === 5 ? 100 : null,
                        ];
                    }
                }
            }
        }
        $this->upsertInChunks(
            'indicator_targets',
            $targetRows,
            ['indicator_assignment_id', 'period', 'period_type'],
            ['period_end', 'target_type', 'value', 'active', 'is_demo', 'updated_at'],
        );
        $this->upsertInChunks(
            'indicator_entries',
            collect($entrySpecifications)->map(fn (array $specification): array => [
                'indicator_assignment_id' => $specification['assignment_id'],
                'period' => $specification['period'],
                'status' => $specification['status'],
                'is_demo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['indicator_assignment_id', 'period'],
            ['status', 'is_demo', 'updated_at'],
        );

        $entries = IndicatorEntry::query()
            ->whereIn('indicator_assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy(fn (IndicatorEntry $entry): string => $entry->indicator_assignment_id.'|'.$entry->period->format('Y-m-d'));
        $revisionRows = collect($entrySpecifications)->map(function (array $specification) use ($entries, $now, $programmeLeadId): array {
            $entry = $entries[$specification['assignment_id'].'|'.$specification['period']];

            return [
                'indicator_entry_id' => $entry->id,
                'indicator_definition_version_id' => $specification['indicator_definition_version_id'],
                'user_id' => $programmeLeadId,
                'version' => 1,
                'status' => $specification['status'],
                'value_state' => 'reported',
                'numeric_value' => $specification['numeric_value'],
                'numerator' => $specification['numerator'],
                'denominator' => $specification['denominator'],
                'notes' => 'Synthetic demonstration record.',
                'submitted_at' => $specification['period'].' 10:00:00',
                'approved_at' => $specification['status'] === 'APPROVED' ? $specification['period'].' 15:00:00' : null,
                'is_demo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();
        $this->upsertInChunks(
            'entry_revisions',
            $revisionRows,
            ['indicator_entry_id', 'version'],
            [
                'indicator_definition_version_id', 'user_id', 'status', 'value_state', 'numeric_value',
                'numerator', 'denominator', 'notes', 'submitted_at', 'approved_at', 'is_demo', 'updated_at',
            ],
        );

        $revisions = DB::table('entry_revisions')
            ->whereIn('indicator_entry_id', $entries->pluck('id'))
            ->where('version', 1)
            ->get()
            ->keyBy('indicator_entry_id');
        $finalEntryRows = collect($entrySpecifications)->map(function (array $specification) use ($entries, $now, $revisions): array {
            $entry = $entries[$specification['assignment_id'].'|'.$specification['period']];
            $revision = $revisions[$entry->id];

            return [
                'indicator_assignment_id' => $specification['assignment_id'],
                'period' => $specification['period'],
                'current_revision_id' => $revision->id,
                'approved_revision_id' => $specification['status'] === 'APPROVED' ? $revision->id : null,
                'status' => $specification['status'],
                'is_demo' => true,
                'created_at' => $entry->created_at,
                'updated_at' => $now,
            ];
        })->all();
        $this->upsertInChunks(
            'indicator_entries',
            $finalEntryRows,
            ['indicator_assignment_id', 'period'],
            ['current_revision_id', 'approved_revision_id', 'status', 'is_demo', 'updated_at'],
        );

        $existingActionRevisionIds = DB::table('approval_actions')->whereIn('entry_revision_id', $revisions->pluck('id'))->pluck('entry_revision_id')->flip();
        $actionRows = collect($entrySpecifications)
            ->reject(fn (array $specification): bool => $specification['status'] === 'SUBMITTED')
            ->map(function (array $specification) use ($entries, $existingActionRevisionIds, $now, $revisions, $seniorProgrammeLeadId): ?array {
                $entry = $entries[$specification['assignment_id'].'|'.$specification['period']];
                $revision = $revisions[$entry->id];
                if ($existingActionRevisionIds->has($revision->id)) {
                    return null;
                }

                return [
                    'entry_revision_id' => $revision->id,
                    'user_id' => $seniorProgrammeLeadId,
                    'action' => $specification['status'],
                    'comment' => $specification['status'] === 'APPROVED' ? 'Demo review completed.' : 'Demo record requires clarification.',
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->values()
            ->all();
        $this->insertInChunks('approval_actions', $actionRows);
    }

    /** @return array<int, array<int, string>> */
    private function standardProjectCentreCodes(): array
    {
        return [
            ['SS-ISBT', 'MLC-KB', 'EC-PN'],
            ['GPS-C2', 'GPS-AR', 'GUPS-RC', 'NIOS-GG', 'GPS-TH'],
            ['WOD-NH', 'WOD-SDIC'],
            ['GGIC-DI', 'GGIC-SU', 'GGIC-MG', 'GGIC-GA'],
            ['REG-DDN', 'REG-USN'],
            ['SA-RP'],
            ['SNC-IN'],
            ['BSH-AR', 'GSH-RR'],
            ['SS-ISBT', 'MLC-KB', 'GPS-C2', 'GPS-AR', 'GUPS-RC', 'WOD-NH', 'WOD-SDIC', 'SNC-IN', 'SA-RP', 'GGIC-DI', 'GPS-TH', 'BSH-AR', 'GSH-RR', 'GGIC-SU', 'GGIC-MG', 'GGIC-GA'],
            ['SS-ISBT', 'MLC-KB', 'GPS-C2', 'GPS-AR', 'GUPS-RC', 'WOD-NH', 'WOD-SDIC', 'SNC-IN', 'SA-RP', 'GGIC-DI', 'GPS-TH', 'BSH-AR', 'GSH-RR', 'GGIC-SU', 'GGIC-MG', 'GGIC-GA'],
            ['SS-ISBT', 'MLC-KB', 'EC-PN', 'GPS-C2', 'GPS-AR', 'GUPS-RC', 'SA-RP', 'GGIC-DI', 'GPS-TH', 'GGIC-SU', 'GGIC-MG', 'GGIC-GA'],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows
     * @param  array<int, string>  $uniqueBy
     * @param  array<int, string>  $update
     */
    private function upsertInChunks(string $table, array $rows, array $uniqueBy, array $update): void
    {
        collect($rows)->chunk(25)->each(
            fn ($chunk) => DB::table($table)->upsert($chunk->all(), $uniqueBy, $update),
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function insertInChunks(string $table, array $rows): void
    {
        collect($rows)->chunk(25)->each(
            fn ($chunk) => DB::table($table)->insert($chunk->all()),
        );
    }

    /** @return array<int, Centre> */
    private function createTataCentres(): array
    {
        $projects = Project::query()->whereIn('code', ['PRG-01', 'PRG-02'])->get()->keyBy('code');
        $centres = [];

        foreach (TataIndicatorService::CENTRE_CODES as $group => $codes) {
            $project = $projects[$group === 'SS' ? 'PRG-01' : 'PRG-02'];
            foreach ($codes as $code) {
                $centre = Centre::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => "Tata preview reporting location {$code}",
                        'type' => $group === 'SQEP' ? 'Partner School' : 'Education Centre',
                        'region' => 'Dehradun',
                        'provenance' => 'synthetic Tata preview reporting location',
                        'active' => true,
                        'is_demo' => true,
                    ],
                );
                $project->centres()->syncWithoutDetaching([
                    $centre->id => [
                        'is_demo' => true,
                        'provenance' => 'synthetic Tata preview assignment',
                    ],
                ]);
                $centres[] = $centre;
            }
        }

        return $centres;
    }

    private function assignPreviewUsers(): void
    {
        $projectIds = Project::query()->pluck('id');
        $centreIds = Centre::query()->pluck('id');
        $userIds = User::query()->where('is_demo', true)->pluck('id');

        DB::table('project_user_assignments')->upsert(
            $userIds->crossJoin($projectIds)
                ->map(fn (array $ids): array => ['user_id' => $ids[0], 'project_id' => $ids[1], 'is_demo' => true])
                ->all(),
            ['user_id', 'project_id'],
            ['is_demo'],
        );
        DB::table('centre_user_assignments')->upsert(
            $userIds->crossJoin($centreIds)
                ->map(fn (array $ids): array => ['user_id' => $ids[0], 'centre_id' => $ids[1], 'is_demo' => true])
                ->all(),
            ['user_id', 'centre_id'],
            ['is_demo'],
        );
    }

    /** @param array<int, Centre> $centres */
    private function assignTataDonor(array $centres): void
    {
        $donor = Donor::query()->updateOrCreate(
            ['code' => 'TATA'],
            [
                'name' => 'TATA Trusts',
                'notes' => 'Synthetic donor record for the Vercel preview.',
                'active' => true,
                'is_demo' => true,
            ],
        );

        foreach ($centres as $centre) {
            CentreDonor::query()->firstOrCreate(
                [
                    'centre_id' => $centre->id,
                    'donor_id' => $donor->id,
                    'starts_on' => '2026-04-01',
                ],
                [
                    'is_primary' => true,
                    'notes' => 'Synthetic donor assignment for the Vercel preview.',
                    'is_demo' => true,
                ],
            );
        }
    }

    /** @param array<int, int> $indicatorIds */
    private function createTataTargets(array $indicatorIds): void
    {
        $periods = collect(range(0, 11))
            ->map(fn (int $offset): CarbonImmutable => CarbonImmutable::create(2026, 4, 1)->addMonths($offset));
        $now = now();
        $rows = IndicatorAssignment::query()
            ->whereIn('indicator_definition_id', $indicatorIds)
            ->with('indicator')
            ->get()
            ->flatMap(fn (IndicatorAssignment $assignment) => $periods->map(
                fn (CarbonImmutable $period): array => [
                    'indicator_assignment_id' => $assignment->id,
                    'period' => $period->toDateString(),
                    'period_type' => 'monthly',
                    'period_end' => $period->endOfMonth()->toDateString(),
                    'target_type' => 'numeric',
                    'value' => $assignment->indicator->data_type === 'percentage' ? 80 : 25,
                    'notes' => 'Synthetic target for the Vercel preview.',
                    'active' => true,
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ))
            ->all();

        IndicatorTarget::query()->upsert(
            $rows,
            ['indicator_assignment_id', 'period', 'period_type'],
            ['period_end', 'target_type', 'value', 'notes', 'active', 'is_demo', 'updated_at'],
        );
    }
}
