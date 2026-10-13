<?php

namespace App\Filament\Resources\IndicatorDefinitions\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class IndicatorDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('project_id')
                    ->relationship('project', 'name')
                    ->required(),
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                Select::make('category')
                    ->options(array_combine(config('impact.categories'), config('impact.categories')))
                    ->required(),
                TextInput::make('unit')
                    ->required(),
                Select::make('data_type')
                    ->options(array_combine(config('impact.data_types'), config('impact.data_types')))
                    ->required(),
                Select::make('frequency')
                    ->options(['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual'])
                    ->required()
                    ->default('monthly'),
                Select::make('time_aggregation')
                    ->options(array_combine(config('impact.aggregations'), config('impact.aggregations')))
                    ->required(),
                Select::make('centre_aggregation')
                    ->options(array_combine(config('impact.aggregations'), config('impact.aggregations')))
                    ->required(),
                Select::make('direction')
                    ->options(['higher' => 'Higher is better', 'lower' => 'Lower is better', 'range' => 'Target range', 'status' => 'Status based'])
                    ->required()
                    ->default('higher'),
                TextInput::make('min_value')
                    ->numeric(),
                TextInput::make('max_value')
                    ->numeric(),
                TextInput::make('precision')
                    ->required()
                    ->numeric()
                    ->default(2),
                TextInput::make('max_length')
                    ->required()
                    ->numeric()
                    ->default(2000),
                DatePicker::make('effective_date')
                    ->default('2026-04-01')
                    ->required(),
                Select::make('centres')
                    ->relationship('centres', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),
                Toggle::make('evidence_required')
                    ->default(false)
                    ->required(),
                Select::make('allowed_extensions')
                    ->multiple()
                    ->options(['pdf' => 'PDF', 'jpg' => 'JPG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WEBP', 'docx' => 'DOCX', 'xlsx' => 'XLSX', 'csv' => 'CSV'])
                    ->default(['pdf', 'jpg', 'png']),
                TextInput::make('max_file_kb')
                    ->required()
                    ->numeric()
                    ->default(5120),
                TextInput::make('max_files')
                    ->required()
                    ->numeric()
                    ->default(5),
                Toggle::make('active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
