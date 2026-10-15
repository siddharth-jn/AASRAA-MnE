<?php

namespace App\Filament\Resources\Centres\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CentresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Project code')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Project name')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Project type')
                    ->searchable(),
                TextColumn::make('region')
                    ->searchable(),
                TextColumn::make('capacity')
                    ->label('Capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label('Programmes')
                    ->counts('projects')
                    ->sortable(),
                TextColumn::make('source_url')
                    ->searchable(),
                TextColumn::make('provenance')
                    ->searchable(),
                IconColumn::make('active')
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
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
