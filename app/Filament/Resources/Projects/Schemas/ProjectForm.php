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
                Select::make('programme_id')
                    ->relationship('programme', 'name')
                    ->required(),
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                Select::make('lead_id')
                    ->label('Project lead')
                    ->relationship('lead', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Select the staff member whose actual account name should appear on the project dashboard.'),
                Select::make('centres')
                    ->relationship('centres', 'name')
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
