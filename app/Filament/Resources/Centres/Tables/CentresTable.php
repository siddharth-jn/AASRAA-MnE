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
                TextColumn::make('source_project_id')
                    ->label('Project ID')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Project code')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Project name')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('lead.name')
                    ->label('Project lead')
                    ->placeholder('Not assigned')
                    ->searchable(),
                TextColumn::make('source_program')
                    ->label('Programme')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_timing_slot')
                    ->label('Timing slot')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('source_donor_name')
                    ->label('Source donor')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('donors.name')
                    ->label('Reporting donors')
                    ->badge()
                    ->separator(',')
                    ->placeholder('Uses source fallback'),
                TextColumn::make('source_description')
                    ->label('Description')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Active' ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('source_synced_at')
                    ->label('Imported on')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('source_location')
                    ->label('Source location')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_started_date')
                    ->label('Start date')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_poc_name')
                    ->label('POC name')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_poc_contact')
                    ->label('POC contact')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_poc_designation')
                    ->label('POC designation')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_address')
                    ->label('Address')
                    ->placeholder('—')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_budget_head')
                    ->label('Budget head')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Local project type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('region')
                    ->label('Local region')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('capacity')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('projects_count')
                    ->label('Programme links')
                    ->counts('projects')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provenance')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                IconColumn::make('active')
                    ->label('Locally active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_demo')
                    ->boolean()
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
