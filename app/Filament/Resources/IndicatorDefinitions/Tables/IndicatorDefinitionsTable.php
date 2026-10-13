<?php

namespace App\Filament\Resources\IndicatorDefinitions\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IndicatorDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')
                    ->searchable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('category')
                    ->searchable(),
                TextColumn::make('unit')
                    ->searchable(),
                TextColumn::make('data_type')
                    ->searchable(),
                TextColumn::make('frequency')
                    ->searchable(),
                TextColumn::make('time_aggregation')
                    ->searchable(),
                TextColumn::make('centre_aggregation')
                    ->searchable(),
                TextColumn::make('direction')
                    ->searchable(),
                TextColumn::make('min_value')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('max_value')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('precision')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('max_length')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('effective_date')
                    ->date()
                    ->sortable(),
                IconColumn::make('evidence_required')
                    ->boolean(),
                TextColumn::make('max_file_kb')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('max_files')
                    ->numeric()
                    ->sortable(),
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
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
