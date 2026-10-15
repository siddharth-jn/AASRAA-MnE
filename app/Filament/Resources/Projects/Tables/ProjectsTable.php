<?php

namespace App\Filament\Resources\Projects\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Programme code')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Programme name')
                    ->description('Managed by the organisation database')
                    ->searchable(),
                TextColumn::make('lead.name')
                    ->label('Programme lead')
                    ->searchable(),
                TextColumn::make('centres_count')
                    ->label('Projects')
                    ->counts('centres')
                    ->sortable(),
                IconColumn::make('active')
                    ->label('Active')
                    ->boolean(),
                IconColumn::make('is_demo')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
