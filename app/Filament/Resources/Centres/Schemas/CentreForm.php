<?php

namespace App\Filament\Resources\Centres\Schemas;

use App\Models\Centre;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CentreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Local M&E management')
                    ->description('These fields are maintained locally in AASRAA MnE. The imported project snapshot below remains read-only.')
                    ->schema([
                        Select::make('lead_id')
                            ->label('Project lead')
                            ->relationship(
                                'lead',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('active', true)
                                    ->whereIn('role', ['ASSISTANT PROGRAM LEAD', 'PROGRAM LEAD', 'SENIOR PROGRAM LEAD']),
                            )
                            ->searchable()
                            ->preload()
                            ->helperText('Selecting a lead also grants that person access to this project and its assigned programme.'),
                        Select::make('type')
                            ->label('Project type')
                            ->options(array_combine(config('impact.location_types'), config('impact.location_types')))
                            ->required(),
                        Select::make('projects')
                            ->label('Reporting programmes')
                            ->relationship('projects', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Choose the programmes under which this project reports.')
                            ->columnSpanFull(),
                        TextEntry::make('assigned_staff')
                            ->label('Assigned staff')
                            ->state(fn (?Centre $record): string => $record?->users()->orderBy('name')->pluck('name')->join(', ') ?: 'None assigned')
                            ->columnSpanFull(),
                        Toggle::make('active')
                            ->label('Active project')
                            ->helperText('Turn this off to archive the project while retaining its reporting history.')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Imported project information')
                    ->description('Read-only snapshot retained from the projects database. Missing source values are shown explicitly and no live synchronization is performed.')
                    ->visible(fn (?Centre $record): bool => $record?->source_project_id !== null)
                    ->schema([
                        TextEntry::make('source_project_id_display')
                            ->label('Project ID')
                            ->state(fn (?Centre $record): string => (string) $record?->source_project_id),
                        TextEntry::make('local_project_code')
                            ->label('Local project code')
                            ->state(fn (?Centre $record): string => $record?->code ?? '—'),
                        TextEntry::make('source_project_name')
                            ->label('Project name')
                            ->state(fn (?Centre $record): string => $record?->name ?? '—'),
                        TextEntry::make('source_programme')
                            ->label('Source programme')
                            ->state(fn (?Centre $record): string => $record?->source_program ?: 'Not available'),
                        TextEntry::make('source_status_display')
                            ->label('Source status')
                            ->state(fn (?Centre $record): string => $record?->source_status ?: 'Not available'),
                        TextEntry::make('source_timing_slot_display')
                            ->label('Timing slot')
                            ->state(fn (?Centre $record): string => $record?->source_timing_slot ?: 'Not available'),
                        TextEntry::make('source_started_date_display')
                            ->label('Start date')
                            ->state(fn (?Centre $record): string => $record?->source_started_date?->format('d M Y') ?? 'Not available'),
                        TextEntry::make('source_location_display')
                            ->label('Source location')
                            ->state(fn (?Centre $record): string => $record?->source_location ?: 'Not available'),
                        TextEntry::make('source_address_display')
                            ->label('Address')
                            ->state(fn (?Centre $record): string => $record?->source_address ?: 'Not available')
                            ->columnSpanFull(),
                        TextEntry::make('source_poc_name_display')
                            ->label('Point of contact')
                            ->state(fn (?Centre $record): string => $record?->source_poc_name ?: 'Not available'),
                        TextEntry::make('source_poc_designation_display')
                            ->label('Contact designation')
                            ->state(fn (?Centre $record): string => $record?->source_poc_designation ?: 'Not available'),
                        TextEntry::make('source_poc_contact_display')
                            ->label('Contact details')
                            ->state(fn (?Centre $record): string => $record?->source_poc_contact ?: 'Not available'),
                        TextEntry::make('source_donor_id_display')
                            ->label('Source donor ID')
                            ->state(fn (?Centre $record): string => $record?->source_donor_id === null ? 'Not available' : (string) $record->source_donor_id),
                        TextEntry::make('source_donor_display')
                            ->label('Source donor name')
                            ->state(fn (?Centre $record): string => $record?->source_donor_name ?: 'Not available'),
                        TextEntry::make('source_budget_head_display')
                            ->label('Budget head')
                            ->state(fn (?Centre $record): string => $record?->source_budget_head ?: 'Not available'),
                        TextEntry::make('source_description_display')
                            ->label('Source description')
                            ->state(fn (?Centre $record): string => $record?->source_description ?: 'Not available')
                            ->columnSpanFull(),
                        TextEntry::make('source_synced_at_display')
                            ->label('Imported on')
                            ->state(fn (?Centre $record): string => $record?->source_synced_at?->format('d M Y, H:i') ?? 'Not available'),
                    ])
                    ->columns(2),
                Section::make('Local project identity')
                    ->description('Used only for projects that are not linked to the projects database.')
                    ->visible(fn (?Centre $record): bool => $record?->source_project_id === null)
                    ->schema([
                        TextInput::make('code')
                            ->label('Project code')
                            ->required()
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label('Project name')
                            ->required(),
                        TextInput::make('region')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }
}
