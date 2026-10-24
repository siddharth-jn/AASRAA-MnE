<?php

namespace App\Filament\Resources\Centres\Schemas;

use App\Models\Centre;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CentreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('source_project_id')
                    ->label('Authoritative project ID')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('code')
                    ->label('Project code')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Project name')
                    ->disabled(fn (?Centre $record): bool => $record?->source_project_id !== null)
                    ->dehydrated(fn (?Centre $record): bool => $record?->source_project_id === null)
                    ->required(),
                TextInput::make('source_program')
                    ->label('Authoritative programme')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('source_timing_slot')
                    ->label('Timing slot')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('source_donor_name')
                    ->label('Donor')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('source_description')
                    ->label('Authoritative description')
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
                Select::make('type')
                    ->label('Project type')
                    ->options(array_combine(config('impact.location_types'), config('impact.location_types')))
                    ->required(),
                TextInput::make('region')
                    ->required(),
                TextInput::make('capacity')
                    ->numeric(),
                TextInput::make('source_url')
                    ->url(),
                TextInput::make('provenance')
                    ->required()
                    ->default('operational'),
                Select::make('projects')
                    ->label('Programmes')
                    ->relationship('projects', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Select the programmes this project reports under.')
                    ->columnSpanFull(),
                Toggle::make('active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
