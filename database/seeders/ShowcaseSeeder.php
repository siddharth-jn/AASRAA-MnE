<?php

namespace Database\Seeders;

use App\Models\ApprovalAction;
use App\Models\AuditLog;
use App\Models\EntryRevision;
use App\Models\EvidenceFile;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorDefinitionVersion;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\DemoDataService;
use App\Services\IndicatorReportingPeriodService;
use App\Services\TargetService;
use App\Services\VarianceService;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;
use ZipArchive;

class ShowcaseSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $workflowUsers = [];

    public function run(DemoDataService $demoDataService, ?int $year = null): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Showcase data may only be seeded in a local or testing environment.');
        }

        $showcaseYear = $year ?? now()->year;
        if ($showcaseYear > now()->year) {
            throw new RuntimeException('Showcase data cannot be generated for a future year.');
        }

        $administrator = User::query()
            ->where('role', 'ADMIN')
            ->where('active', true)
            ->where('is_demo', false)
            ->orderBy('id')
            ->first();

        if (! $administrator) {
            throw new RuntimeException('Create an active, non-demo administrator before seeding showcase data.');
        }

        $projects = Project::query()
            ->where('is_demo', false)
            ->where('active', true)
            ->whereHas('centres', fn ($query) => $query->where('centres.is_demo', false)->where('centres.active', true))
            ->with(['programme', 'centres' => fn ($query) => $query->where('centres.is_demo', false)->where('centres.active', true)->orderBy('centres.name')])
            ->orderBy('name')
            ->get();

        if ($projects->isEmpty()) {
            throw new RuntimeException('Synchronize the authoritative project catalogue before seeding showcase data.');
        }

        $demoDataService->clear();
        $endMonth = $showcaseYear === now()->year ? now()->month : 12;

        try {
            DB::transaction(function () use ($administrator, $endMonth, $projects, $showcaseYear): void {
                $this->seedWorkflowUsers($projects);
                $this->seedCoreReporting($administrator, $projects, $showcaseYear, $endMonth);
                $this->seedShowcaseScenarios($administrator, $projects, $showcaseYear, $endMonth);
                $this->seedNotificationsAndAudit($administrator, $showcaseYear);
            });
        } catch (Throwable $exception) {
            Storage::disk(config('impact.evidence_disk', 'local'))->deleteDirectory('evidence/showcase');

            throw $exception;
        }
    }

    /** @param Collection<int, Project> $projects */
    private function seedWorkflowUsers(Collection $projects): void
    {
        $accounts = [
            'ASSISTANT PROGRAM LEAD' => ['Showcase Assistant Programme Lead', 'showcase.apl@aasraa.local'],
            'PROGRAM LEAD' => ['Showcase Programme Lead', 'showcase.pl@aasraa.local'],
            'SENIOR PROGRAM LEAD' => ['Showcase Senior Programme Lead', 'showcase.spl@aasraa.local'],
            'M&E HEAD' => ['Showcase M&E Head', 'showcase.mne@aasraa.local'],
            'CEO' => ['Showcase CEO', 'showcase.ceo@aasraa.local'],
            'CFO' => ['Showcase CFO', 'showcase.cfo@aasraa.local'],
        ];
        $projectIds = $projects->modelKeys();
        $centreIds = $projects->pluck('centres')->flatten()->pluck('id')->unique()->values()->all();

        foreach ($accounts as $role => [$name, $email]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => config('impact.demo_password') ?: 'Showcase-password1',
                'role' => $role,
                'active' => true,
                'is_demo' => true,
            ]);
            if (in_array($role, ['ASSISTANT PROGRAM LEAD', 'PROGRAM LEAD', 'SENIOR PROGRAM LEAD'], true)) {
                $user->projects()->attach($projectIds, ['is_demo' => true]);
                $user->centres()->attach($centreIds, ['is_demo' => true]);
            }
            $this->workflowUsers[$role] = $user;
        }
    }

    /** @param Collection<int, Project> $projects */
    private function seedCoreReporting(User $administrator, Collection $projects, int $year, int $endMonth): void
    {
        $dataTypes = ['integer', 'decimal', 'percentage', 'ratio', 'calculated', 'integer', 'decimal'];
        $timeAggregations = ['SUM', 'AVERAGE', 'LATEST SNAPSHOT', 'RATIO', 'RATIO', 'UNIQUE COUNT', 'WEIGHTED AVERAGE'];
        $centreAggregations = ['SUM', 'AVERAGE', 'AVERAGE', 'RATIO', 'RATIO', 'UNIQUE COUNT', 'WEIGHTED AVERAGE'];
        $directions = ['higher', 'lower', 'range', 'higher', 'higher', 'higher', 'lower'];
        $categories = ['Output', 'Outcome', 'KPI', 'Outcome', 'KPI', 'Activity', 'Input'];

        foreach ($projects->values() as $projectIndex => $project) {
            $dataType = $dataTypes[$projectIndex % count($dataTypes)];
            $indicator = $this->createIndicator($project, [
                'code' => sprintf('SHOWCASE-%d-CORE-%02d', $year, $projectIndex + 1),
                'name' => 'Monthly programme participation',
                'description' => "Synthetic monthly trend for {$project->name}; safe to remove before operational import.",
                'category' => $categories[$projectIndex % count($categories)],
                'unit' => in_array($dataType, ['percentage', 'ratio', 'calculated'], true) ? '%' : ($dataType === 'decimal' ? 'average' : 'people'),
                'data_type' => $dataType,
                'frequency' => 'monthly',
                'time_aggregation' => $timeAggregations[$projectIndex % count($timeAggregations)],
                'centre_aggregation' => $centreAggregations[$projectIndex % count($centreAggregations)],
                'direction' => $directions[$projectIndex % count($directions)],
                'target_tracking' => true,
                'approval_required' => false,
                'min_value' => 0,
                'max_value' => in_array($dataType, ['percentage', 'ratio', 'calculated'], true) ? 100 : null,
                'precision' => $dataType === 'integer' ? 0 : 1,
            ], $year);
            $definitionVersion = $indicator->versions()->latest('version')->firstOrFail();
            $representativeCentreId = $project->centres->firstOrFail()->id;

            foreach ($project->centres as $centreIndex => $centre) {
                $assignment = IndicatorAssignment::query()->create([
                    'indicator_definition_id' => $indicator->id,
                    'centre_id' => $centre->id,
                    'required' => true,
                    'is_demo' => true,
                ]);
                $months = $centre->id === $representativeCentreId ? range(1, $endMonth) : [$endMonth];

                foreach ($months as $month) {
                    $period = CarbonImmutable::create($year, $month, 1);
                    $seed = 35 + ($projectIndex * 6) + ($centreIndex % 11) + ($month * 2);
                    $value = $this->valueFor($dataType, $seed);
                    if ($indicator->time_aggregation === 'WEIGHTED AVERAGE') {
                        $value['weight'] = 1 + ($centreIndex % 3);
                    }
                    $this->createTarget($assignment, $period, $indicator->direction, $seed);
                    $this->createEntry(
                        $assignment,
                        $definitionVersion,
                        $administrator,
                        $period,
                        WorkflowStatus::FinalApproved,
                        $value,
                        null,
                        'Synthetic approved result for interface and trend review.',
                    );
                }
            }
        }
    }

    /** @param Collection<int, Project> $projects */
    private function seedShowcaseScenarios(User $administrator, Collection $projects, int $year, int $endMonth): void
    {
        $period = CarbonImmutable::create($year, $endMonth, 1);
        $previousPeriod = $period->subMonth();
        $scenarios = [
            [
                'name' => 'Safeguarding checklist completed', 'data_type' => 'boolean', 'category' => 'Milestone', 'unit' => 'yes/no',
                'frequency' => 'monthly', 'time_aggregation' => 'LATEST SNAPSHOT', 'centre_aggregation' => 'LATEST SNAPSHOT',
                'status' => WorkflowStatus::Draft, 'value' => ['boolean_value' => true], 'target_tracking' => false,
            ],
            [
                'name' => 'Most recent community event', 'data_type' => 'date', 'category' => 'Activity', 'unit' => 'date',
                'frequency' => 'quarterly', 'time_aggregation' => 'NON-AGGREGATABLE', 'centre_aggregation' => 'NON-AGGREGATABLE',
                'status' => WorkflowStatus::PendingSeniorProgrammeLead, 'value' => ['date_value' => $period->addDays(6)->toDateString()], 'target_tracking' => false,
                'evidence_required' => true, 'max_files' => 5,
            ],
            [
                'name' => 'Implementation highlight', 'data_type' => 'text', 'category' => 'Qualitative Indicator', 'unit' => 'summary',
                'frequency' => 'monthly', 'time_aggregation' => 'NON-AGGREGATABLE', 'centre_aggregation' => 'NON-AGGREGATABLE',
                'status' => WorkflowStatus::ReturnedForCorrection, 'value' => ['text_value' => 'Attendance improved after family follow-up.'], 'target_tracking' => false,
            ],
            [
                'name' => 'Detailed case narrative', 'data_type' => 'long_text', 'category' => 'Qualitative Indicator', 'unit' => 'narrative',
                'frequency' => 'half_yearly', 'time_aggregation' => 'NON-AGGREGATABLE', 'centre_aggregation' => 'NON-AGGREGATABLE',
                'status' => WorkflowStatus::Rejected, 'value' => ['text_value' => 'Synthetic narrative used to demonstrate the rejected review state without containing personal information.'], 'target_tracking' => false,
            ],
            [
                'name' => 'Programme delivery rating', 'data_type' => 'single_select', 'category' => 'Outcome', 'unit' => 'rating',
                'frequency' => 'quarterly', 'time_aggregation' => 'STATUS', 'centre_aggregation' => 'STATUS',
                'status' => WorkflowStatus::PendingMneHead, 'value' => ['text_value' => 'On track'], 'target_tracking' => false,
                'options' => ['At risk', 'On track', 'Complete'],
            ],
            [
                'name' => 'Support services provided', 'data_type' => 'multi_select', 'category' => 'Output', 'unit' => 'services',
                'frequency' => 'half_yearly', 'time_aggregation' => 'UNIQUE COUNT', 'centre_aggregation' => 'UNIQUE COUNT',
                'status' => WorkflowStatus::FinalApproved, 'value' => ['json_value' => ['Education', 'Health referral']], 'target_tracking' => false,
                'options' => ['Education', 'Nutrition', 'Health referral', 'Counselling'], 'approval_required' => false,
            ],
            [
                'name' => 'Annual implementation status', 'data_type' => 'status', 'category' => 'KPI', 'unit' => 'status',
                'frequency' => 'annual', 'time_aggregation' => 'STATUS', 'centre_aggregation' => 'STATUS',
                'status' => WorkflowStatus::FinalApproved, 'value' => ['text_value' => 'On track'], 'target_tracking' => true,
                'direction' => 'status', 'options' => ['At risk', 'On track', 'Complete'],
                'evidence_required' => true, 'evidence_review_required' => true, 'max_files' => 3,
            ],
        ];

        $evidenceRevisions = [];
        $textScenario = null;
        $booleanScenario = null;

        foreach ($scenarios as $scenarioIndex => $scenario) {
            $project = $projects[$scenarioIndex % $projects->count()];
            $centre = $project->centres->firstOrFail();
            $indicator = $this->createIndicator($project, [
                ...$scenario,
                'code' => sprintf('SHOWCASE-%d-TYPE-%02d', $year, $scenarioIndex + 1),
                'description' => 'Synthetic coverage example for the '.str_replace('_', ' ', $scenario['data_type']).' input type.',
                'approval_required' => $scenario['approval_required'] ?? true,
                'evidence_required' => $scenario['evidence_required'] ?? false,
                'evidence_review_required' => $scenario['evidence_review_required'] ?? false,
                'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx', 'csv'],
                'min_files' => ($scenario['evidence_required'] ?? false) ? 1 : 0,
                'max_files' => $scenario['max_files'] ?? 5,
                'direction' => $scenario['direction'] ?? 'higher',
                'options' => $scenario['options'] ?? null,
            ], $year);
            $definitionVersion = $indicator->versions()->latest('version')->firstOrFail();
            $assignment = IndicatorAssignment::query()->create([
                'indicator_definition_id' => $indicator->id,
                'centre_id' => $centre->id,
                'required' => true,
                'is_demo' => true,
            ]);
            $action = match ($scenario['status']) {
                WorkflowStatus::PendingMneHead => WorkflowStatus::PendingMneHead,
                WorkflowStatus::FinalApproved => ($scenario['approval_required'] ?? true) ? WorkflowStatus::FinalApproved : null,
                WorkflowStatus::ReturnedForCorrection => WorkflowStatus::ReturnedForCorrection,
                WorkflowStatus::Rejected => WorkflowStatus::Rejected,
                default => null,
            };
            $revision = $this->createEntry(
                $assignment,
                $definitionVersion,
                $this->workflowUsers['PROGRAM LEAD'],
                $period,
                $scenario['status'],
                $scenario['value'],
                $action,
                'Synthetic workflow scenario created for local interface review.',
            );

            if ($scenario['data_type'] === 'date') {
                $evidenceRevisions['documents'] = $revision;
            }
            if ($scenario['data_type'] === 'status') {
                $evidenceRevisions['structured'] = $revision;
                IndicatorTarget::query()->create([
                    'indicator_assignment_id' => $assignment->id,
                    'period' => CarbonImmutable::create($year, 1, 1),
                    'period_type' => 'annual',
                    'period_end' => CarbonImmutable::create($year, 12, 31),
                    'target_type' => 'status',
                    'status_value' => 'On track',
                    'notes' => 'Synthetic annual status target.',
                    'active' => true,
                    'is_demo' => true,
                ]);
            }
            if ($scenario['data_type'] === 'text') {
                $textScenario = [$assignment, $definitionVersion];
            }
            if ($scenario['data_type'] === 'boolean') {
                $booleanScenario = [$assignment, $definitionVersion];
            }
        }

        if ($booleanScenario) {
            [$assignment, $definitionVersion] = $booleanScenario;
            $this->createEntry(
                $assignment,
                $definitionVersion,
                $administrator,
                $previousPeriod,
                WorkflowStatus::FinalApproved,
                ['value_state' => 'not_applicable'],
                WorkflowStatus::FinalApproved,
                'Not applicable is intentionally represented once.',
            );
        }

        if ($textScenario) {
            [$assignment, $definitionVersion] = $textScenario;
            $this->createCorrectedEntry($assignment, $definitionVersion, $this->workflowUsers['PROGRAM LEAD'], $previousPeriod);
        }

        $this->attachEvidence($administrator, $evidenceRevisions, $year);
    }

    /** @param array<string, mixed> $attributes */
    private function createIndicator(Project $project, array $attributes, int $year): IndicatorDefinition
    {
        return IndicatorDefinition::query()->create([
            'project_id' => $project->id,
            'description' => 'Synthetic showcase indicator.',
            'category' => 'Output',
            'unit' => 'count',
            'data_type' => 'integer',
            'value_required' => true,
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'direction' => 'higher',
            'target_tracking' => false,
            'min_value' => null,
            'max_value' => null,
            'precision' => 0,
            'max_length' => 2000,
            'options' => null,
            'calculation_method' => in_array($attributes['data_type'] ?? null, ['ratio', 'calculated'], true) ? 'ratio_percentage' : null,
            'numerator_label' => in_array($attributes['data_type'] ?? null, ['ratio', 'calculated'], true) ? 'Participants achieving outcome' : null,
            'denominator_label' => in_array($attributes['data_type'] ?? null, ['ratio', 'calculated'], true) ? 'Participants assessed' : null,
            'effective_date' => CarbonImmutable::create($year, 1, 1),
            'evidence_required' => false,
            'allowed_extensions' => ['pdf', 'jpg', 'png'],
            'max_file_kb' => 5120,
            'min_files' => 0,
            'max_files' => 5,
            'evidence_review_required' => false,
            'approval_required' => true,
            'active' => true,
            'is_demo' => true,
            ...collect($attributes)->except(['status', 'value'])->all(),
        ]);
    }

    private function createTarget(IndicatorAssignment $assignment, CarbonImmutable $period, string $direction, float $seed): void
    {
        IndicatorTarget::query()->create([
            'indicator_assignment_id' => $assignment->id,
            'period' => $period,
            'period_type' => 'monthly',
            'period_end' => $period->endOfMonth(),
            'target_type' => $direction === 'range' ? 'range' : 'numeric',
            'value' => $direction === 'range' ? null : ($direction === 'lower' ? max(5, $seed - 8) : $seed + 4),
            'minimum_value' => $direction === 'range' ? max(0, $seed - 5) : null,
            'maximum_value' => $direction === 'range' ? $seed + 5 : null,
            'notes' => 'Synthetic monthly target.',
            'active' => true,
            'is_demo' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function valueFor(string $dataType, float $seed): array
    {
        if (in_array($dataType, ['ratio', 'calculated'], true)) {
            $numerator = min(95, (int) $seed);

            return ['numeric_value' => $numerator, 'numerator' => $numerator, 'denominator' => 100];
        }

        return ['numeric_value' => $dataType === 'integer' ? (int) $seed : round($seed + 0.4, 1)];
    }

    /** @param array<string, mixed> $value
     * @return array{variance_percentage: float|null, variance_flagged: bool, variance_explanation: string|null}
     */
    private function syntheticVariance(IndicatorAssignment $assignment, CarbonImmutable $period, array $value): array
    {
        $target = app(TargetService::class)->findForPeriod($assignment->id, $period);
        $variance = app(VarianceService::class)->evaluate($assignment->indicator, $target, ['value_state' => 'reported', ...$value]);

        return [
            'variance_percentage' => $variance['percentage'],
            'variance_flagged' => $variance['flagged'],
            'variance_explanation' => $variance['flagged'] ? 'Synthetic explanation: delivery conditions caused the result to differ materially from target.' : null,
        ];
    }

    /** @param array<string, mixed> $value */
    private function createEntry(
        IndicatorAssignment $assignment,
        IndicatorDefinitionVersion $definitionVersion,
        User $submitter,
        CarbonImmutable $period,
        string $status,
        array $value,
        ?string $approvalAction,
        string $notes,
    ): EntryRevision {
        $period = app(IndicatorReportingPeriodService::class)->start($period, $assignment->indicator->frequency);
        $entry = IndicatorEntry::query()->create([
            'indicator_assignment_id' => $assignment->id,
            'period' => $period,
            'status' => $status,
            'is_demo' => true,
        ]);
        $revision = $entry->revisions()->create([
            'indicator_definition_version_id' => $definitionVersion->id,
            'user_id' => $submitter->id,
            'version' => 1,
            'status' => $status,
            'value_state' => $value['value_state'] ?? 'reported',
            'numeric_value' => $value['numeric_value'] ?? null,
            'numerator' => $value['numerator'] ?? null,
            'denominator' => $value['denominator'] ?? null,
            'weight' => $value['weight'] ?? null,
            'text_value' => $value['text_value'] ?? null,
            'date_value' => $value['date_value'] ?? null,
            'boolean_value' => $value['boolean_value'] ?? null,
            'json_value' => $value['json_value'] ?? null,
            'notes' => $notes,
            ...$this->syntheticVariance($assignment, $period, $value),
            'submitted_at' => $status === WorkflowStatus::Draft ? null : $period->addDays(10)->setTime(10, 0),
            'approved_at' => $status === WorkflowStatus::FinalApproved ? $period->addDays(12)->setTime(15, 0) : null,
            'is_demo' => true,
        ]);
        $entry->update([
            'current_revision_id' => $revision->id,
            'approved_revision_id' => $status === WorkflowStatus::FinalApproved ? $revision->id : null,
        ]);

        if ($approvalAction === WorkflowStatus::FinalApproved) {
            ApprovalAction::query()->create([
                'entry_revision_id' => $revision->id,
                'user_id' => $this->workflowUsers['SENIOR PROGRAM LEAD']->id,
                'stage' => 'SENIOR PROGRAM LEAD',
                'action' => WorkflowStatus::PendingMneHead,
                'comment' => 'Synthetic Senior Programme Lead approval.',
                'is_demo' => true,
            ]);
            ApprovalAction::query()->create([
                'entry_revision_id' => $revision->id,
                'user_id' => $this->workflowUsers['M&E HEAD']->id,
                'stage' => 'M&E HEAD',
                'action' => WorkflowStatus::FinalApproved,
                'comment' => 'Synthetic final M&E approval.',
                'is_demo' => true,
            ]);
        } elseif ($approvalAction) {
            ApprovalAction::query()->create([
                'entry_revision_id' => $revision->id,
                'user_id' => $this->workflowUsers['SENIOR PROGRAM LEAD']->id,
                'stage' => 'SENIOR PROGRAM LEAD',
                'action' => $approvalAction,
                'comment' => 'Synthetic Senior Programme Lead review action.',
                'is_demo' => true,
            ]);
        }

        return $revision;
    }

    private function createCorrectedEntry(
        IndicatorAssignment $assignment,
        IndicatorDefinitionVersion $definitionVersion,
        User $submitter,
        CarbonImmutable $period,
    ): void {
        $period = app(IndicatorReportingPeriodService::class)->start($period, $assignment->indicator->frequency);
        $entry = IndicatorEntry::query()->create([
            'indicator_assignment_id' => $assignment->id,
            'period' => $period,
            'status' => WorkflowStatus::FinalApproved,
            'is_demo' => true,
        ]);
        $returnedRevision = $entry->revisions()->create([
            'indicator_definition_version_id' => $definitionVersion->id,
            'user_id' => $submitter->id,
            'version' => 1,
            'status' => WorkflowStatus::ReturnedForCorrection,
            'value_state' => 'reported',
            'text_value' => 'Initial summary requiring clarification.',
            'notes' => 'Synthetic first revision.',
            'submitted_at' => $period->addDays(8),
            'is_demo' => true,
        ]);
        ApprovalAction::query()->create([
            'entry_revision_id' => $returnedRevision->id,
            'user_id' => $this->workflowUsers['SENIOR PROGRAM LEAD']->id,
            'stage' => 'SENIOR PROGRAM LEAD',
            'action' => WorkflowStatus::ReturnedForCorrection,
            'comment' => 'Please explain the change in participation.',
            'is_demo' => true,
        ]);
        $approvedRevision = $entry->revisions()->create([
            'indicator_definition_version_id' => $definitionVersion->id,
            'user_id' => $submitter->id,
            'version' => 2,
            'status' => WorkflowStatus::FinalApproved,
            'value_state' => 'reported',
            'text_value' => 'Participation improved after additional family outreach.',
            'notes' => 'Synthetic corrected revision.',
            'submitted_at' => $period->addDays(11),
            'approved_at' => $period->addDays(12),
            'is_demo' => true,
        ]);
        ApprovalAction::query()->create([
            'entry_revision_id' => $approvedRevision->id,
            'user_id' => $this->workflowUsers['M&E HEAD']->id,
            'stage' => 'M&E HEAD',
            'action' => WorkflowStatus::FinalApproved,
            'comment' => 'Clarification received.',
            'is_demo' => true,
        ]);
        $entry->update([
            'current_revision_id' => $approvedRevision->id,
            'approved_revision_id' => $approvedRevision->id,
        ]);
    }

    /** @param array<string, EntryRevision> $revisions */
    private function attachEvidence(User $administrator, array $revisions, int $year): void
    {
        if (! isset($revisions['documents'], $revisions['structured'])) {
            return;
        }

        $files = $this->createEvidenceFiles($year);
        foreach ($files as $index => $file) {
            $revision = $index < 5 ? $revisions['documents'] : $revisions['structured'];
            EvidenceFile::query()->create([
                'entry_revision_id' => $revision->id,
                'user_id' => $administrator->id,
                'disk' => 'local',
                'path' => $file['path'],
                'original_name' => $file['name'],
                'mime_type' => $file['mime'],
                'size' => Storage::disk('local')->size($file['path']),
                'is_demo' => true,
            ]);
        }
    }

    /** @return array<int, array{path: string, name: string, mime: string}> */
    private function createEvidenceFiles(int $year): array
    {
        $directory = "evidence/showcase/{$year}";
        Storage::disk('local')->makeDirectory($directory);

        Storage::disk('local')->put("{$directory}/summary.pdf", $this->minimalPdf('Synthetic showcase evidence'));
        $this->writeImage("{$directory}/activity.jpg", 'jpeg');
        $this->writeImage("{$directory}/attendance.jpeg", 'jpeg');
        $this->writeImage("{$directory}/location.png", 'png');
        $this->writeImage("{$directory}/visit.webp", 'webp');
        $this->writeDocx("{$directory}/case-note.docx");
        $this->writeXlsx("{$directory}/attendance.xlsx");
        Storage::disk('local')->put("{$directory}/participants.csv", "project,period,participants\nSynthetic project,{$year}-10,42\n");

        return [
            ['path' => "{$directory}/summary.pdf", 'name' => 'showcase-summary.pdf', 'mime' => 'application/pdf'],
            ['path' => "{$directory}/activity.jpg", 'name' => 'showcase-activity.jpg', 'mime' => 'image/jpeg'],
            ['path' => "{$directory}/attendance.jpeg", 'name' => 'showcase-attendance.jpeg', 'mime' => 'image/jpeg'],
            ['path' => "{$directory}/location.png", 'name' => 'showcase-location.png', 'mime' => 'image/png'],
            ['path' => "{$directory}/visit.webp", 'name' => 'showcase-visit.webp', 'mime' => 'image/webp'],
            ['path' => "{$directory}/case-note.docx", 'name' => 'showcase-case-note.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['path' => "{$directory}/attendance.xlsx", 'name' => 'showcase-attendance.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['path' => "{$directory}/participants.csv", 'name' => 'showcase-participants.csv', 'mime' => 'text/csv'],
        ];
    }

    private function writeImage(string $path, string $format): void
    {
        $image = imagecreatetruecolor(320, 180);
        if ($image === false) {
            throw new RuntimeException('Unable to create showcase image evidence.');
        }
        $background = imagecolorallocate($image, 230, 247, 245);
        $foreground = imagecolorallocate($image, 15, 118, 110);
        imagefill($image, 0, 0, $background);
        imagestring($image, 5, 35, 80, 'AASRAA SHOWCASE EVIDENCE', $foreground);
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 85),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 85),
            default => throw new RuntimeException("Unsupported showcase image format: {$format}"),
        };
        $contents = ob_get_clean();
        imagedestroy($image);
        if (! is_string($contents)) {
            throw new RuntimeException('Unable to encode showcase image evidence.');
        }
        Storage::disk('local')->put($path, $contents);
    }

    private function writeDocx(string $path): void
    {
        $absolutePath = Storage::disk('local')->path($path);
        $archive = new ZipArchive;
        if ($archive->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create showcase DOCX evidence.');
        }
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $archive->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Synthetic showcase evidence without personal information.</w:t></w:r></w:p></w:body></w:document>');
        $archive->close();
    }

    private function writeXlsx(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Project', 'Period', 'Participants'],
            ['Synthetic project', 'October', 42],
        ]);
        (new Xlsx($spreadsheet))->save(Storage::disk('local')->path($path));
        $spreadsheet->disconnectWorksheets();
    }

    private function minimalPdf(string $text): string
    {
        $stream = "BT /F1 16 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $objectNumber = $index + 1;
            $pdf .= "{$objectNumber} 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private function seedNotificationsAndAudit(User $administrator, int $year): void
    {
        $notifications = [
            ['title' => 'Submission requires approval', 'message' => 'A synthetic date entry is awaiting review.', 'url' => route('approvals.index'), 'kind' => 'approval_required'],
            ['title' => 'Submission returned for correction', 'message' => 'A synthetic qualitative entry demonstrates reviewer feedback.', 'url' => route('dashboard'), 'kind' => 'returned_for_correction'],
            ['title' => 'Showcase data ready', 'message' => "Minimal {$year} reporting coverage is available for interface review.", 'url' => route('dashboard'), 'kind' => 'showcase'],
        ];

        foreach ($notifications as $index => $notification) {
            $administrator->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => WorkflowNotification::class,
                'data' => [...$notification, 'showcase' => true],
                'read_at' => $index === 2 ? now() : null,
            ]);
        }

        foreach (['configured indicators', 'submitted reporting data', 'reviewed evidence'] as $action) {
            AuditLog::query()->create([
                'user_id' => $administrator->id,
                'action' => $action,
                'subject_type' => self::class,
                'subject_id' => $year,
                'changes' => ['showcase' => true, 'year' => $year],
                'is_demo' => true,
            ]);
        }
    }
}
