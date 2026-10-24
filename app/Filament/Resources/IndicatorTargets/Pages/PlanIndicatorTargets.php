<?php

namespace App\Filament\Resources\IndicatorTargets\Pages;

use App\Filament\Resources\IndicatorTargets\IndicatorTargetResource;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Services\IndicatorReportingPeriodService;
use App\Services\TargetService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanIndicatorTargets extends Page
{
    protected static string $resource = IndicatorTargetResource::class;

    protected string $view = 'filament-panels::pages.page';

    public ?int $indicatorId = null;

    public ?int $financialYearStart = null;

    /** @var array<int, int> */
    public array $selectedAssignmentIds = [];

    /** @var array<int, string> */
    public array $selectedPeriodKeys = [];

    public bool $sameTargetAllProjects = true;

    /** @var array<string, array<string, mixed>> */
    public array $targets = [];

    public function mount(): void
    {
        $today = CarbonImmutable::now();
        $startMonth = (int) config('impact.financial_year_start_month', 4);
        $this->financialYearStart = $today->month < $startMonth ? $today->year - 1 : $today->year;
    }

    public function getTitle(): string
    {
        return 'Set indicator targets';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('1. Choose where targets apply')
                    ->description('Select the indicator, financial year, projects, and reporting periods.')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->schema([
                        Select::make('indicatorId')
                            ->label('Indicator')
                            ->options(fn (): array => $this->indicators()
                                ->mapWithKeys(fn (IndicatorDefinition $indicator): array => [
                                    $indicator->id => $indicator->project->name.' · '.$indicator->name,
                                ])
                                ->all())
                            ->placeholder('Select an indicator')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->required(),
                        Select::make('financialYearStart')
                            ->label('Financial year')
                            ->options($this->financialYearOptions())
                            ->native(false)
                            ->live()
                            ->required(),
                        Select::make('selectedAssignmentIds')
                            ->label('Projects')
                            ->options(fn (): array => $this->assignments()
                                ->mapWithKeys(fn (IndicatorAssignment $assignment): array => [
                                    $assignment->id => $assignment->centre->name,
                                ])
                                ->all())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->placeholder('Select projects')
                            ->helperText('All assigned projects are selected when you choose an indicator.')
                            ->visible(fn (): bool => $this->indicator() !== null)
                            ->required(),
                        Select::make('selectedPeriodKeys')
                            ->label('Reporting periods')
                            ->options(fn (): array => collect($this->periodRows())->pluck('label', 'key')->all())
                            ->multiple()
                            ->native(false)
                            ->placeholder('Select one or more periods')
                            ->helperText(fn (): string => $this->indicator()
                                ? config('impact.frequencies.'.$this->indicator()->frequency).' periods are shown automatically.'
                                : 'Choose an indicator first.')
                            ->visible(fn (): bool => $this->indicator() !== null)
                            ->live()
                            ->required(),
                    ])
                    ->columns(2),
                EmptyState::make('Choose at least one reporting period')
                    ->description('Target fields will appear here only for the periods you select above.')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (): bool => $this->indicator() !== null && $this->selectedPeriodRows() === []),
                Section::make('2. Enter target values')
                    ->description(fn (): string => count($this->selectedPeriodRows()).' period(s) selected for '.count($this->selectedAssignmentIds).' project(s).')
                    ->icon(Heroicon::OutlinedChartBarSquare)
                    ->schema(fn (): array => [
                        Toggle::make('sameTargetAllProjects')
                            ->label('Use the same target for every selected project')
                            ->helperText('Turn this off to enter a different target for each project.')
                            ->live()
                            ->columnSpanFull(),
                        ...$this->targetValueComponents(),
                    ])
                    ->columns(2)
                    ->footerActions([
                        Action::make('saveTargets')
                            ->label('Save targets')
                            ->icon(Heroicon::OutlinedCheck)
                            ->action(fn (): mixed => $this->save()),
                    ])
                    ->visible(fn (): bool => $this->selectedPeriodRows() !== []),
            ]);
    }

    /** @return array<int, string> */
    public function financialYearOptions(): array
    {
        $currentStart = CarbonImmutable::now()->month < (int) config('impact.financial_year_start_month', 4)
            ? CarbonImmutable::now()->year - 1
            : CarbonImmutable::now()->year;

        return collect(range($currentStart - 5, $currentStart + 10))
            ->mapWithKeys(fn (int $year): array => [$year => 'FY '.$year.'–'.substr((string) ($year + 1), -2)])
            ->all();
    }

    /** @return Collection<int, IndicatorDefinition> */
    public function indicators(): Collection
    {
        return IndicatorDefinition::query()
            ->with('project')
            ->where('target_tracking', true)
            ->whereIn('data_type', app(TargetService::class)->supportedDataTypes())
            ->whereHas('assignments')
            ->orderBy('name')
            ->get();
    }

    public function indicator(): ?IndicatorDefinition
    {
        if (! $this->indicatorId) {
            return null;
        }

        return IndicatorDefinition::query()
            ->where('target_tracking', true)
            ->find($this->indicatorId);
    }

    /** @return Collection<int, IndicatorAssignment> */
    public function assignments(): Collection
    {
        return $this->indicator()?->assignments()->with('centre')->orderBy('centre_id')->get()
            ?? new Collection;
    }

    /** @return array<int, array{key: string, label: string, start: CarbonImmutable}> */
    public function periodRows(): array
    {
        $indicator = $this->indicator();
        if (! $indicator || $this->financialYearStart < 2000 || $this->financialYearStart > 2100) {
            return [];
        }

        $periods = app(IndicatorReportingPeriodService::class);
        $first = CarbonImmutable::create($this->financialYearStart, (int) config('impact.financial_year_start_month', 4), 1);
        $rows = [];

        for ($month = 0; $month < 12; $month += $periods->months($indicator->frequency)) {
            $start = $first->addMonths($month);
            if (! $periods->isAvailable($indicator, $start)) {
                continue;
            }

            $rows[] = [
                'key' => $start->format('Ymd'),
                'label' => $periods->label($start, $indicator->frequency),
                'start' => $start,
            ];
        }

        return $rows;
    }

    public function updatedIndicatorId(): void
    {
        $this->selectedPeriodKeys = [];
        $this->selectedAssignmentIds = $this->assignments()->modelKeys();
        $this->sameTargetAllProjects = true;
        $this->loadTargets();
    }

    public function updatedFinancialYearStart(): void
    {
        $this->selectedPeriodKeys = [];
        $this->sameTargetAllProjects = true;
        $this->loadTargets();
    }

    /** @return array<int, array{key: string, label: string, start: CarbonImmutable}> */
    public function selectedPeriodRows(): array
    {
        return collect($this->periodRows())
            ->whereIn('key', $this->selectedPeriodKeys)
            ->values()
            ->all();
    }

    public function loadTargets(): void
    {
        $this->targets = [];
        $assignments = $this->assignments();
        $rows = $this->periodRows();
        if ($assignments->isEmpty() || $rows === []) {
            return;
        }

        $existingTargets = IndicatorTarget::query()
            ->whereIn('indicator_assignment_id', $assignments->modelKeys())
            ->where('period_type', $this->indicator()->frequency)
            ->whereBetween('period', [$rows[0]['start'], $rows[array_key_last($rows)]['start']])
            ->get()
            ->groupBy(fn (IndicatorTarget $target): string => $target->period->format('Ymd'));
        foreach ($rows as $row) {
            $periodTargets = $existingTargets->get($row['key'], collect())->keyBy('indicator_assignment_id');
            $projectValues = [];
            foreach ($assignments as $assignment) {
                $projectValues[$assignment->id] = $this->valuesFromTarget($periodTargets->get($assignment->id));
            }

            $values = array_values($projectValues);
            $periodProjectsMatch = count(array_unique(array_map('serialize', $values))) <= 1;
            $this->targets[$row['key']] = [
                'shared' => $periodProjectsMatch ? ($values[0] ?? $this->emptyValues()) : $this->emptyValues(),
                'projects' => $projectValues,
            ];
        }
    }

    public function save(): void
    {
        $indicator = $this->indicator();
        $assignments = $this->assignments()->keyBy('id');
        $selectedIds = collect($this->selectedAssignmentIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $availablePeriodKeys = collect($this->periodRows())->pluck('key');
        $selectedPeriodKeys = collect($this->selectedPeriodKeys)->map(fn (mixed $key): string => (string) $key)->unique()->values();
        if (! $indicator || $this->financialYearStart < 2000 || $this->financialYearStart > 2100 || $selectedIds->isEmpty() || $selectedIds->diff($assignments->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['indicatorId' => 'Choose an indicator, financial year, and at least one assigned project.']);
        }
        if ($selectedPeriodKeys->isEmpty() || $selectedPeriodKeys->diff($availablePeriodKeys)->isNotEmpty()) {
            throw ValidationException::withMessages(['selectedPeriodKeys' => 'Choose at least one reporting period.']);
        }

        $targetType = app(TargetService::class)->typeForIndicator($indicator);
        if ($targetType === null) {
            throw ValidationException::withMessages(['indicatorId' => 'Targets are not supported for this indicator input type.']);
        }
        $payloads = [];
        foreach ($this->selectedPeriodRows() as $row) {
            foreach ($selectedIds as $assignmentId) {
                $values = $this->sameTargetAllProjects
                    ? ($this->targets[$row['key']]['shared'] ?? [])
                    : ($this->targets[$row['key']]['projects'][$assignmentId] ?? []);
                if ($this->isEmpty($values)) {
                    continue;
                }

                $this->validateValues($values, $targetType, $row['label']);
                $payloads[] = [
                    'indicator_assignment_id' => $assignmentId,
                    'period_type' => $indicator->frequency,
                    'period' => $row['start']->toDateString(),
                    'target_type' => $targetType,
                    'value' => $values['value'] ?? null,
                    'minimum_value' => $values['minimum'] ?? null,
                    'maximum_value' => $values['maximum'] ?? null,
                    'status_value' => $values['status'] ?? null,
                    'active' => true,
                ];
            }
        }

        if ($payloads === []) {
            Notification::make()->title('Enter at least one target to save.')->warning()->send();

            return;
        }

        DB::transaction(function () use ($payloads): void {
            foreach ($payloads as $payload) {
                app(TargetService::class)->save($payload);
            }
        });

        $this->loadTargets();
        Notification::make()->title('Targets saved for '.count($payloads).' project-period combinations.')->success()->send();
    }

    /** @return array<int, Fieldset> */
    private function targetValueComponents(): array
    {
        $indicator = $this->indicator();
        if (! $indicator) {
            return [];
        }

        $assignments = $this->assignments()->whereIn('id', $this->selectedAssignmentIds);

        return collect($this->selectedPeriodRows())
            ->map(function (array $period) use ($assignments, $indicator): Fieldset {
                $fields = $this->sameTargetAllProjects
                    ? $this->targetInputs("targets.{$period['key']}.shared", null, $indicator)
                    : $assignments
                        ->flatMap(fn (IndicatorAssignment $assignment): array => $this->targetInputs(
                            "targets.{$period['key']}.projects.{$assignment->id}",
                            $assignment->centre->name,
                            $indicator,
                        ))
                        ->all();

                return Fieldset::make($period['label'])
                    ->schema($fields)
                    ->columns(2)
                    ->columnSpanFull(! $this->sameTargetAllProjects);
            })
            ->all();
    }

    /** @return array<int, Select|TextInput> */
    private function targetInputs(string $statePath, ?string $projectName, IndicatorDefinition $indicator): array
    {
        $labelPrefix = $projectName ? $projectName.' — ' : '';
        $targetType = app(TargetService::class)->typeForIndicator($indicator);

        if ($targetType === 'range') {
            return [
                TextInput::make("{$statePath}.minimum")
                    ->label($labelPrefix.'Minimum')
                    ->numeric()
                    ->step($this->targetStep($indicator))
                    ->minValue($this->targetMinimum($indicator))
                    ->maxValue($this->targetMaximum($indicator)),
                TextInput::make("{$statePath}.maximum")
                    ->label($labelPrefix.'Maximum')
                    ->numeric()
                    ->step($this->targetStep($indicator))
                    ->minValue($this->targetMinimum($indicator))
                    ->maxValue($this->targetMaximum($indicator)),
            ];
        }

        if ($targetType === 'status') {
            return [
                Select::make("{$statePath}.status")
                    ->label($labelPrefix.'Expected status')
                    ->options(app(TargetService::class)->statusOptionsForIndicator($indicator))
                    ->native(false)
                    ->columnSpanFull(),
            ];
        }

        return [
            TextInput::make("{$statePath}.value")
                ->label($labelPrefix.'Target')
                ->numeric()
                ->step($this->targetStep($indicator))
                ->minValue($this->targetMinimum($indicator))
                ->maxValue($this->targetMaximum($indicator))
                ->suffix($indicator->unit)
                ->columnSpanFull(),
        ];
    }

    private function targetStep(IndicatorDefinition $indicator): float|int
    {
        return app(TargetService::class)->stepForIndicator($indicator);
    }

    private function targetMinimum(IndicatorDefinition $indicator): ?float
    {
        return app(TargetService::class)->minimumForIndicator($indicator);
    }

    private function targetMaximum(IndicatorDefinition $indicator): ?float
    {
        return app(TargetService::class)->maximumForIndicator($indicator);
    }

    /** @return array{value: string, minimum: string, maximum: string, status: string} */
    private function emptyValues(): array
    {
        return ['value' => '', 'minimum' => '', 'maximum' => '', 'status' => ''];
    }

    /** @return array{value: string, minimum: string, maximum: string, status: string} */
    private function valuesFromTarget(?IndicatorTarget $target): array
    {
        if (! $target) {
            return $this->emptyValues();
        }

        return [
            'value' => $target->value === null ? '' : (string) $target->value,
            'minimum' => $target->minimum_value === null ? '' : (string) $target->minimum_value,
            'maximum' => $target->maximum_value === null ? '' : (string) $target->maximum_value,
            'status' => $target->status_value ?? '',
        ];
    }

    /** @param array<string, mixed> $values */
    private function isEmpty(array $values): bool
    {
        return collect($values)->every(fn (mixed $value): bool => blank($value));
    }

    /** @param array<string, mixed> $values */
    private function validateValues(array $values, string $targetType, string $periodLabel): void
    {
        $valid = match ($targetType) {
            'range' => is_numeric($values['minimum'] ?? null) && is_numeric($values['maximum'] ?? null) && (float) $values['minimum'] <= (float) $values['maximum'],
            'status' => filled($values['status'] ?? null),
            default => is_numeric($values['value'] ?? null),
        };

        if (! $valid) {
            throw ValidationException::withMessages(['targets' => "Enter a valid target for {$periodLabel}. For a range, fill both minimum and maximum."]);
        }
    }
}
