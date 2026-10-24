<?php

namespace App\Filament\Resources\IndicatorDefinitions\Schemas;

use App\Services\IndicatorService;
use App\Services\TargetService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class IndicatorDefinitionForm
{
    public static function configure(Schema $schema, int|string|null $projectId = null): Schema
    {
        $dataTypes = collect(config('impact.data_types'))
            ->mapWithKeys(fn (string $type): array => [$type => Str::headline($type)])
            ->all();
        $targetableDataTypes = app(TargetService::class)->supportedDataTypes();

        return $schema->components([
            Wizard::make([
                Step::make('Basic details')
                    ->description('Name and classify the indicator.')
                    ->schema([
                        $projectId === null
                            ? Select::make('project_id')
                                ->label('Programme')
                                ->relationship('project', 'name')
                                ->live()
                                ->required()
                            : Hidden::make('project_id')
                                ->default($projectId),
                        TextInput::make('code')
                            ->helperText('A permanent unique code, for example SS-LIT-SESSIONS.')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->required()
                            ->columnSpanFull(),
                        Select::make('category')
                            ->options(array_combine(config('impact.categories'), config('impact.categories')))
                            ->required(),
                        TextInput::make('unit')
                            ->placeholder('people, sessions, %, status')
                            ->required(),
                        Select::make('frequency')
                            ->options(config('impact.frequencies'))
                            ->live()
                            ->required()
                            ->default('monthly'),
                        TextInput::make('effective_date')
                            ->label('Reporting starts from')
                            ->type('month')
                            ->default('2026-04')
                            ->formatStateUsing(fn (mixed $state): ?string => filled($state) ? CarbonImmutable::parse($state)->format('Y-m') : null)
                            ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? CarbonImmutable::createFromFormat('Y-m', $state)->startOfMonth()->toDateString() : null)
                            ->rules(['date_format:Y-m'])
                            ->helperText(fn (Get $get): string => match ($get('frequency')) {
                                'quarterly' => 'The fiscal quarter containing this month is the first quarter available for reporting.',
                                'half_yearly' => 'The fiscal half-year containing this month is the first half-year available for reporting.',
                                'annual' => 'The financial year containing this month is the first year available for reporting.',
                                default => 'The first month for which this indicator can be reported.',
                            })
                            ->required(),
                    ])
                    ->columns(2),
                Step::make('Input and validation')
                    ->description('Control the generated reporting field and accepted values.')
                    ->schema([
                        Select::make('data_type')
                            ->options($dataTypes)
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($targetableDataTypes): void {
                                $options = app(IndicatorService::class)->acrossProjectOptions((string) $state);
                                if (! array_key_exists((string) $get('centre_aggregation'), $options)) {
                                    $set('centre_aggregation', array_key_first($options));
                                }
                                if (in_array($state, ['single_select', 'status'], true)) {
                                    $set('time_aggregation', 'STATUS');
                                }
                                if (! in_array($state, $targetableDataTypes, true)) {
                                    $set('target_tracking', false);
                                } elseif (in_array($state, ['boolean', 'single_select', 'status'], true)) {
                                    $set('direction', 'status');
                                } elseif ($get('direction') === 'status') {
                                    $set('direction', 'higher');
                                }
                            })
                            ->required(),
                        Toggle::make('value_required')
                            ->label('Require a value when reported')
                            ->default(true),
                        TextInput::make('min_value')
                            ->label('Minimum numeric value')
                            ->numeric()
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['integer', 'decimal', 'percentage', 'ratio', 'calculated'], true)),
                        TextInput::make('max_value')
                            ->label('Maximum numeric value')
                            ->numeric()
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['integer', 'decimal', 'percentage', 'ratio', 'calculated'], true)),
                        TextInput::make('precision')
                            ->label('Decimal places')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(4)
                            ->default(2)
                            ->required()
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['decimal', 'percentage', 'ratio', 'calculated'], true)),
                        TextInput::make('max_length')
                            ->numeric()
                            ->minValue(1)
                            ->default(2000)
                            ->required()
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['text', 'long_text'], true)),
                        DatePicker::make('minimum_date')
                            ->visible(fn (Get $get): bool => $get('data_type') === 'date'),
                        DatePicker::make('maximum_date')
                            ->visible(fn (Get $get): bool => $get('data_type') === 'date'),
                        TagsInput::make('options')
                            ->label('Accepted options')
                            ->helperText('Press Enter after each option. These values generate the reporting dropdown.')
                            ->required(fn (Get $get): bool => in_array($get('data_type'), ['single_select', 'multi_select', 'status'], true))
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['single_select', 'multi_select', 'status'], true))
                            ->columnSpanFull(),
                        Select::make('calculation_method')
                            ->options(['ratio_percentage' => 'Numerator ÷ denominator × 100'])
                            ->default('ratio_percentage')
                            ->required(fn (Get $get): bool => in_array($get('data_type'), ['ratio', 'calculated'], true))
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['ratio', 'calculated'], true)),
                        TextInput::make('numerator_label')
                            ->placeholder('Students passed')
                            ->required(fn (Get $get): bool => in_array($get('data_type'), ['ratio', 'calculated'], true))
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['ratio', 'calculated'], true)),
                        TextInput::make('denominator_label')
                            ->placeholder('Students assessed')
                            ->required(fn (Get $get): bool => in_array($get('data_type'), ['ratio', 'calculated'], true))
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), ['ratio', 'calculated'], true)),
                    ])
                    ->columns(2),
                Step::make('Project assignment')
                    ->description('Choose the projects that must report this indicator.')
                    ->schema([
                        Select::make('centres')
                            ->label('Projects')
                            ->relationship(
                                'centres',
                                'name',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                    ->when($projectId ?? $get('project_id'), fn (Builder $centreQuery, int|string $selectedProjectId): Builder => $centreQuery->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($selectedProjectId)))
                                    ->orderBy('name'),
                            )
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateHydrated(fn (mixed $state, Set $set): mixed => $set('target_centre_ids', array_values($state ?? [])))
                            ->afterStateUpdated(fn (mixed $state, Set $set): mixed => $set('target_centre_ids', array_values($state ?? [])))
                            ->required()
                            ->minItems(1)
                            ->helperText('Only projects already assigned to the selected programme are available.')
                            ->columnSpanFull(),
                        Hidden::make('target_centre_ids'),
                    ]),
                Step::make('Aggregation')
                    ->description('Define how values combine across time and projects, and whether performance is compared with targets.')
                    ->schema([
                        Select::make('time_aggregation')
                            ->options(array_combine(config('impact.aggregations'), config('impact.aggregations')))
                            ->required(),
                        Select::make('centre_aggregation')
                            ->label('Across projects')
                            ->options(fn (Get $get): array => app(IndicatorService::class)->acrossProjectOptions((string) $get('data_type')))
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                if ($state === 'STATUS') {
                                    $set('time_aggregation', 'STATUS');
                                }
                            })
                            ->helperText('Used when All projects is selected. Each project is first calculated using the time method.')
                            ->required(),
                        Toggle::make('target_tracking')
                            ->label('Compare results with targets when available')
                            ->helperText('Reporting remains open even when no target has been set for a period. Plan targets separately under Indicator targets.')
                            ->live()
                            ->visible(fn (Get $get): bool => in_array($get('data_type'), $targetableDataTypes, true))
                            ->default(true),
                        Select::make('direction')
                            ->options(fn (Get $get): array => in_array($get('data_type'), ['boolean', 'single_select', 'status'], true)
                                ? ['status' => 'Match the expected value']
                                : [
                                    'higher' => 'Higher is better',
                                    'lower' => 'Lower is better',
                                    'range' => 'Stay within a target range',
                                ])
                            ->required(fn (Get $get): bool => (bool) $get('target_tracking'))
                            ->visible(fn (Get $get): bool => (bool) $get('target_tracking'))
                            ->default('higher'),
                    ])
                    ->columns(2),
                Step::make('Evidence and approval')
                    ->description('Choose whether evidence and approval are required. File limits can be adjusted if needed.')
                    ->schema([
                        Toggle::make('evidence_required')
                            ->live()
                            ->afterStateHydrated(function (bool $state, Get $get, Set $set): void {
                                if ($state && (int) $get('min_files') < 1) {
                                    $set('min_files', 1);
                                }
                            })
                            ->afterStateUpdated(function (bool $state, Set $set): void {
                                $set('min_files', $state ? 1 : 0);
                            })
                            ->default(false),
                        Toggle::make('approval_required')
                            ->label('Require approval before results become official')
                            ->default(true),
                        Section::make('Advanced evidence limits')
                            ->description('Defaults allow up to five files of 5 MB each; one file is required when evidence is on.')
                            ->collapsed()
                            ->visible(fn (Get $get): bool => (bool) $get('evidence_required'))
                            ->schema([
                                Select::make('allowed_extensions')
                                    ->multiple()
                                    ->options(fn (): array => collect(config('impact.evidence_extensions'))->mapWithKeys(fn (string $extension): array => [$extension => strtoupper($extension)])->all())
                                    ->default(fn (): array => config('impact.evidence_extensions'))
                                    ->formatStateUsing(fn (mixed $state): array => filled($state) ? (array) $state : config('impact.evidence_extensions'))
                                    ->required(),
                                TextInput::make('max_file_kb')
                                    ->label('Maximum file size (KB)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(5120)
                                    ->required(),
                                TextInput::make('min_files')
                                    ->label('Minimum files')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(20)
                                    ->default(1)
                                    ->required(),
                                TextInput::make('max_files')
                                    ->label('Maximum files')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(20)
                                    ->default(5)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                        Toggle::make('active')
                            ->helperText('Turn this off to archive the definition while keeping its history.')
                            ->default(true),
                    ])
                    ->columns(2),
            ])->columnSpanFull(),
        ]);
    }
}
