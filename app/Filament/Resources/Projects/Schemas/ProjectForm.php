<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Programme code')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Programme codes are supplied by the organisation database.'),
                TextInput::make('name')
                    ->label('Programme name')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Programme names are supplied by the organisation database.'),
                Textarea::make('description')
                    ->label('Monitoring description')
                    ->required()
                    ->columnSpanFull(),
                Select::make('lead_id')
                    ->label('Programme lead')
                    ->relationship('lead', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Select the staff member whose account name should appear on the programme dashboard.'),
                Select::make('centres')
                    ->label('Projects')
                    ->relationship('centres', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Assign the projects that report under this programme.')
                    ->columnSpanFull(),
                Toggle::make('active')
                    ->label('Active in monitoring')
                    ->default(true)
                    ->required(),
            ]);
    }
}
