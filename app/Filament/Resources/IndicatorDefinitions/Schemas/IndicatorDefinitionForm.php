<?php

namespace App\Filament\Resources\IndicatorDefinitions\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class IndicatorDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        $dataTypes = collect(config('impact.data_types'))
            ->mapWithKeys(fn (string $type): array => [$type => Str::headline($type)])
            ->all();

        return $schema->components([
            Wizard::make([
                Step::make('Basic details')
                    ->description('Name and classify the indicator.')
                    ->schema([
                        Select::make('project_id')
                            ->relationship('project', 'name')
                            ->live()
                            ->required(),
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
                            ->required()
                            ->default('monthly'),
                        DatePicker::make('effective_date')
                            ->default('2026-04-01')
                            ->required(),
                    ])
                    ->columns(2),
                Step::make('Input and validation')
                    ->description('Control the generated reporting field and accepted values.')
                    ->schema([
                        Select::make('data_type')
                            ->options($dataTypes)
                            ->live()
                            ->required(),
                        Toggle::make('value_required')
                            ->label('Reported value is required')
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
                Step::make('Centre assignment')
                    ->description('Choose the reporting locations that must provide this indicator.')
                    ->schema([
                        Select::make('centres')
                            ->relationship(
                                'centres',
                                'name',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                    ->when($get('project_id'), fn (Builder $centreQuery, int|string $projectId): Builder => $centreQuery->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId)))
                                    ->orderBy('name'),
                            )
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->minItems(1)
                            ->helperText('Only centres already assigned to the selected project are available.')
                            ->columnSpanFull(),
                    ]),
                Step::make('Aggregation and targets')
                    ->description('Define how values combine across time and centres.')
                    ->schema([
                        Select::make('time_aggregation')
                            ->options(array_combine(config('impact.aggregations'), config('impact.aggregations')))
                            ->required(),
                        Select::make('centre_aggregation')
                            ->options(array_combine(config('impact.aggregations'), config('impact.aggregations')))
                            ->required(),
                        Toggle::make('target_tracking')
                            ->label('Track performance against targets')
                            ->live()
                            ->default(true),
                        Select::make('direction')
                            ->options([
                                'higher' => 'Higher is better',
                                'lower' => 'Lower is better',
                                'range' => 'Target range',
                                'status' => 'Status based',
                            ])
                            ->required(fn (Get $get): bool => (bool) $get('target_tracking'))
                            ->visible(fn (Get $get): bool => (bool) $get('target_tracking'))
                            ->default('higher'),
                    ])
                    ->columns(2),
                Step::make('Evidence and approval')
                    ->description('Set proof and review requirements.')
                    ->schema([
                        Toggle::make('evidence_required')
                            ->live()
                            ->default(false),
                        Toggle::make('evidence_review_required')
                            ->label('Reviewer must examine evidence')
                            ->default(false),
                        Select::make('allowed_extensions')
                            ->multiple()
                            ->options(['pdf' => 'PDF', 'jpg' => 'JPG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WEBP', 'docx' => 'DOCX', 'xlsx' => 'XLSX', 'csv' => 'CSV'])
                            ->default(['pdf', 'jpg', 'png'])
                            ->required(fn (Get $get): bool => (bool) $get('evidence_required'))
                            ->visible(fn (Get $get): bool => (bool) $get('evidence_required')),
                        TextInput::make('max_file_kb')
                            ->label('Maximum file size (KB)')
                            ->numeric()
                            ->minValue(1)
                            ->default(5120)
                            ->required(),
                        TextInput::make('min_files')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(20)
                            ->default(0)
                            ->required(),
                        TextInput::make('max_files')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20)
                            ->default(5)
                            ->required(),
                        Toggle::make('approval_required')
                            ->default(true),
                        Toggle::make('active')
                            ->helperText('Turn this off to archive the definition while keeping its history.')
                            ->default(true),
                    ])
                    ->columns(2),
            ])->columnSpanFull(),
        ]);
    }
}
