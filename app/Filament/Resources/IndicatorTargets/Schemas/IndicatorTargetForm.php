<?php

namespace App\Filament\Resources\IndicatorTargets\Schemas;

use App\Models\IndicatorTarget;
use App\Services\IndicatorReportingPeriodService;
use App\Services\TargetService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class IndicatorTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /** @return array<int, mixed> */
    public static function components(): array
    {
        return [
            Section::make('Target scope')
                ->description('This target belongs to the indicator, project, and reporting period shown below.')
                ->schema([
                    Hidden::make('indicator_assignment_id'),
                    TextEntry::make('indicator_and_project')
                        ->label('Indicator and project')
                        ->state(fn (?IndicatorTarget $record): string => $record?->assignment?->display_name ?? '—')
                        ->columnSpanFull(),
                    TextEntry::make('reporting_period')
                        ->label('Reporting period')
                        ->state(fn (?IndicatorTarget $record): string => $record
                            ? app(IndicatorReportingPeriodService::class)->label($record->period, $record->period_type)
                            : '—')
                        ->columnSpanFull(),
                    Hidden::make('period_type'),
                    Hidden::make('period'),
                ])
                ->columns(2),
            Section::make('Target value')
                ->schema([
                    Hidden::make('target_type'),
                    TextEntry::make('target_format')
                        ->label('Target format')
                        ->state(fn (?IndicatorTarget $record): string => match ($record?->assignment?->indicator ? app(TargetService::class)->typeForIndicator($record->assignment->indicator) : null) {
                            'range' => 'Target range',
                            'status' => 'Expected status',
                            default => 'Numeric target',
                        }),
                    TextInput::make('value')
                        ->label('Target value')
                        ->numeric()
                        ->step(fn (?IndicatorTarget $record): float|int => $record?->assignment?->indicator
                            ? app(TargetService::class)->stepForIndicator($record->assignment->indicator)
                            : 0.01)
                        ->minValue(fn (?IndicatorTarget $record): ?float => $record?->assignment?->indicator
                            ? app(TargetService::class)->minimumForIndicator($record->assignment->indicator)
                            : null)
                        ->maxValue(fn (?IndicatorTarget $record): ?float => $record?->assignment?->indicator
                            ? app(TargetService::class)->maximumForIndicator($record->assignment->indicator)
                            : null)
                        ->required(fn (Get $get): bool => $get('target_type') === 'numeric')
                        ->visible(fn (Get $get): bool => $get('target_type') === 'numeric'),
                    TextInput::make('minimum_value')
                        ->label('Range minimum')
                        ->numeric()
                        ->step(fn (?IndicatorTarget $record): float|int => $record?->assignment?->indicator
                            ? app(TargetService::class)->stepForIndicator($record->assignment->indicator)
                            : 0.01)
                        ->minValue(fn (?IndicatorTarget $record): ?float => $record?->assignment?->indicator
                            ? app(TargetService::class)->minimumForIndicator($record->assignment->indicator)
                            : null)
                        ->maxValue(fn (?IndicatorTarget $record): ?float => $record?->assignment?->indicator
                            ? app(TargetService::class)->maximumForIndicator($record->assignment->indicator)
                            : null)
                        ->required(fn (Get $get): bool => $get('target_type') === 'range')
                        ->visible(fn (Get $get): bool => $get('target_type') === 'range'),
                    TextInput::make('maximum_value')
                        ->label('Range maximum')
                        ->numeric()
                        ->step(fn (?IndicatorTarget $record): float|int => $record?->assignment?->indicator
                            ? app(TargetService::class)->stepForIndicator($record->assignment->indicator)
                            : 0.01)
                        ->minValue(fn (?IndicatorTarget $record): ?float => $record?->assignment?->indicator
                            ? app(TargetService::class)->minimumForIndicator($record->assignment->indicator)
                            : null)
                        ->maxValue(fn (?IndicatorTarget $record): ?float => $record?->assignment?->indicator
                            ? app(TargetService::class)->maximumForIndicator($record->assignment->indicator)
                            : null)
                        ->required(fn (Get $get): bool => $get('target_type') === 'range')
                        ->visible(fn (Get $get): bool => $get('target_type') === 'range'),
                    Select::make('status_value')
                        ->label('Expected status')
                        ->options(fn (?IndicatorTarget $record): array => $record?->assignment?->indicator
                            ? app(TargetService::class)->statusOptionsForIndicator($record->assignment->indicator)
                            : [])
                        ->native(false)
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
