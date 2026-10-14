<?php

namespace App\Filament\Resources\IndicatorDefinitions\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class IndicatorDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->description(fn ($record): string => Str::limit($record->description, 60))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('data_type')
                    ->label('Input type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('centres_count')
                    ->label('Centres')
                    ->counts('centres')
                    ->sortable(),
                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->counts('versions')
                    ->sortable(),
                TextColumn::make('time_aggregation')
                    ->label('Across time')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('centre_aggregation')
                    ->label('Across centres')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('target_tracking')
                    ->label('Targets')
                    ->boolean(),
                IconColumn::make('evidence_required')
                    ->label('Evidence')
                    ->boolean(),
                IconColumn::make('approval_required')
                    ->label('Approval')
                    ->boolean(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('effective_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('project')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category')
                    ->options(array_combine(config('impact.categories'), config('impact.categories'))),
                SelectFilter::make('data_type')
                    ->options(collect(config('impact.data_types'))->mapWithKeys(fn (string $type): array => [$type => Str::headline($type)])->all()),
                SelectFilter::make('frequency')
                    ->options(config('impact.frequencies')),
                TernaryFilter::make('active'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
