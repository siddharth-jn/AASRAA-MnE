<?php

namespace App\Filament\Resources\IndicatorTargets\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class IndicatorTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('assignment.indicator.project.name')
                    ->label('Programme')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assignment.indicator.code')
                    ->label('Indicator')
                    ->searchable(),
                TextColumn::make('assignment.centre.name')
                    ->label('Project')
                    ->searchable(),
                TextColumn::make('period_type')
                    ->badge(),
                TextColumn::make('period')
                    ->label('Starts')
                    ->date()
                    ->sortable(),
                TextColumn::make('period_end')
                    ->label('Ends')
                    ->date(),
                TextColumn::make('target_type')
                    ->badge(),
                TextColumn::make('value')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—'),
                TextColumn::make('minimum_value')
                    ->label('Minimum')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('maximum_value')
                    ->label('Maximum')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status_value')
                    ->label('Expected status')
                    ->placeholder('—'),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period_type')
                    ->options(config('impact.target_periods')),
                SelectFilter::make('target_type')
                    ->options(['numeric' => 'Numeric target', 'range' => 'Target range', 'status' => 'Expected status']),
            ])
            ->defaultSort('period', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
