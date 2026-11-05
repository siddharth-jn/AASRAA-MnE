<?php

namespace App\Filament\Resources\CentreDonors\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CentreDonorsTable
{
    public static function configure(Table $table, bool $showProject = true): Table
    {
        return $table
            ->columns([
                ...($showProject ? [
                    TextColumn::make('centre.name')->label('Project')->searchable()->sortable(),
                ] : []),
                TextColumn::make('donor.name')->label('Donor')->searchable()->sortable(),
                TextColumn::make('starts_on')->label('From')->date()->sortable(),
                TextColumn::make('ends_on')->label('Until')->date()->placeholder('Current')->sortable(),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
