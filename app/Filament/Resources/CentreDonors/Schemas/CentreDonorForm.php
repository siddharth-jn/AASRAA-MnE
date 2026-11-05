<?php

namespace App\Filament\Resources\CentreDonors\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CentreDonorForm
{
    public static function configure(Schema $schema, bool $includeProject = true): Schema
    {
        return $schema
            ->components([
                ...($includeProject ? [
                    Select::make('centre_id')
                        ->label('Project')
                        ->relationship('centre', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                ] : []),
                Select::make('donor_id')
                    ->label('Donor')
                    ->relationship('donor', 'name', modifyQueryUsing: fn ($query) => $query->where('active', true))
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('starts_on')
                    ->label('Effective from')
                    ->required(),
                DatePicker::make('ends_on')
                    ->label('Effective until')
                    ->afterOrEqual('starts_on')
                    ->helperText('Leave blank while the donor assignment remains active.'),
                Toggle::make('is_primary')
                    ->label('Primary donor')
                    ->default(true),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
