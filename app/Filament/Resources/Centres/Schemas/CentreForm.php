<?php

namespace App\Filament\Resources\Centres\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CentreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required(),
                Select::make('type')
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
                    ->relationship('projects', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),
                Toggle::make('active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
