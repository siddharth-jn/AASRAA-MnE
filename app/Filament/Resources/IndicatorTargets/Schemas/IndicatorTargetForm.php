<?php

namespace App\Filament\Resources\IndicatorTargets\Schemas;

use App\Models\IndicatorAssignment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class IndicatorTargetForm
{
    public static function configure(Schema $schema, ?int $indicatorId = null): Schema
    {
        return $schema->components(self::components($indicatorId));
    }

    /** @return array<int, mixed> */
    public static function components(?int $indicatorId = null): array
    {
        return [
            Section::make('Target scope')
                ->description($indicatorId === null
                    ? 'Set a target for one configured indicator and centre.'
                    : 'Set a target for one centre assigned to this indicator.')
                ->schema([
                    Select::make('indicator_assignment_id')
                        ->label($indicatorId === null ? 'Indicator and centre' : 'Centre')
                        ->options(fn (): array => IndicatorAssignment::query()
                            ->with(['indicator.project', 'centre'])
                            ->whereHas('indicator', fn (Builder $query): Builder => $query->where('target_tracking', true))
                            ->when($indicatorId, fn (Builder $query, int $selectedIndicatorId): Builder => $query->where('indicator_definition_id', $selectedIndicatorId))
                            ->get()
                            ->sortBy(fn (IndicatorAssignment $assignment): string => $assignment->display_name)
                            ->mapWithKeys(fn (IndicatorAssignment $assignment): array => [
                                $assignment->id => $indicatorId === null ? $assignment->display_name : $assignment->centre->name,
                            ])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpanFull(),
                    Select::make('period_type')
                        ->options(config('impact.target_periods'))
                        ->default('monthly')
                        ->required(),
                    DatePicker::make('period')
                        ->label('Period containing date')
                        ->helperText('The system converts this to the correct month, fiscal quarter, half-year, or financial year.')
                        ->required(),
                ])
                ->columns(2),
            Section::make('Target value')
                ->schema([
                    Select::make('target_type')
                        ->options(['numeric' => 'Numeric target', 'range' => 'Target range', 'status' => 'Expected status'])
                        ->live()
                        ->default('numeric')
                        ->required(),
                    TextInput::make('value')
                        ->label('Target value')
                        ->numeric()
                        ->required(fn (Get $get): bool => $get('target_type') === 'numeric')
                        ->visible(fn (Get $get): bool => $get('target_type') === 'numeric'),
                    TextInput::make('minimum_value')
                        ->label('Range minimum')
                        ->numeric()
                        ->required(fn (Get $get): bool => $get('target_type') === 'range')
                        ->visible(fn (Get $get): bool => $get('target_type') === 'range'),
                    TextInput::make('maximum_value')
                        ->label('Range maximum')
                        ->numeric()
                        ->required(fn (Get $get): bool => $get('target_type') === 'range')
                        ->visible(fn (Get $get): bool => $get('target_type') === 'range'),
                    TextInput::make('status_value')
                        ->label('Expected status')
                        ->required(fn (Get $get): bool => $get('target_type') === 'status')
                        ->visible(fn (Get $get): bool => $get('target_type') === 'status'),
                    Textarea::make('notes')
                        ->maxLength(2000)
                        ->columnSpanFull(),
                    Toggle::make('active')
                        ->helperText('Deactivate a superseded target instead of deleting it.')
                        ->default(true),
                ])
                ->columns(2),
        ];
    }
}
