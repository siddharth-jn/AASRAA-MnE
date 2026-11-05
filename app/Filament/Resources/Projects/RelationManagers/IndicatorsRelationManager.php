<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\IndicatorDefinitions\Schemas\IndicatorDefinitionForm;
use App\Models\IndicatorDefinition;
use App\Services\IndicatorService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IndicatorsRelationManager extends RelationManager
{
    /** @var array<string, mixed> */
    private array $pendingIndicatorSetup = [];

    protected static string $relationship = 'indicators';

    protected static ?string $title = 'Programme indicators';

    public function form(Schema $schema): Schema
    {
        return IndicatorDefinitionForm::configure($schema, $this->getOwnerRecord()->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->description(fn ($record): string => Str::limit($record->description, 60))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('centres_count')
                    ->label('Projects')
                    ->counts('centres')
                    ->sortable(),
                TextColumn::make('targets_count')
                    ->label('Targets')
                    ->counts('targets')
                    ->sortable(),
                IconColumn::make('target_tracking')
                    ->label('Tracking')
                    ->boolean(),
                IconColumn::make('active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->headerActions([
                CreateAction::make()
                    ->label('Add indicator')
                    ->createAnother(false)
                    ->mutateDataUsing(fn (array $data): array => $this->prepareIndicatorData($data))
                    ->after(fn (IndicatorDefinition $record): IndicatorDefinition => $this->completeIndicatorSetup($record)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data, IndicatorDefinition $record): array => $this->prepareIndicatorData($data, $record))
                    ->after(fn (IndicatorDefinition $record): IndicatorDefinition => $this->completeIndicatorSetup($record)),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareIndicatorData(array $data, ?IndicatorDefinition $indicator = null): array
    {
        $centreIds = collect($data['centres'] ?? $data['target_centre_ids'] ?? $indicator?->centres()->pluck('centres.id') ?? [])
            ->map(fn (mixed $centreId): int => (int) $centreId)
            ->filter()
            ->sort()
            ->values()
            ->all();
        $this->pendingIndicatorSetup = ['centres' => $centreIds, 'target_centre_ids' => $centreIds];

        if ($indicator?->hasHistoricalData()) {
            $currentCentreIds = $indicator->centres()->pluck('centres.id')->map(fn (mixed $centreId): int => (int) $centreId)->sort()->values()->all();
            $newScope = $data['reporting_scope'] ?? $indicator->reporting_scope;
            if ($newScope !== $indicator->reporting_scope || $centreIds !== $currentCentreIds) {
                throw ValidationException::withMessages([
                    'centres' => 'Project assignments and reporting level are locked because targets or entries exist. Archive this indicator and create a replacement instead.',
                ]);
            }
        }

        $data = Arr::except($data, ['centres', 'target_centre_ids']);
        if ($indicator) {
            $data = array_merge($indicator->only(IndicatorDefinition::HISTORICAL_CONFIGURATION_FIELDS), $data);
        }
        $data['project_id'] = $this->getOwnerRecord()->getKey();

        if ($indicator?->hasHistoricalData()) {
            return $data;
        }

        return app(IndicatorService::class)->prepareAndValidate($data);
    }

    private function completeIndicatorSetup(IndicatorDefinition $indicator): IndicatorDefinition
    {
        $centreIds = collect($this->pendingIndicatorSetup['centres'] ?? $this->pendingIndicatorSetup['target_centre_ids'] ?? [])
            ->map(fn (mixed $centreId): int => (int) $centreId)
            ->filter()
            ->values();

        if ($indicator->isProgrammeScoped()) {
            if ($indicator->assignments()->whereNotNull('centre_id')->where(function ($query): void {
                $query->whereHas('entries')->orWhereHas('targets');
            })->exists()) {
                throw ValidationException::withMessages([
                    'reporting_scope' => 'The reporting level cannot be changed after targets or entries exist. Create a new indicator instead.',
                ]);
            }
            $indicator->centres()->sync([]);
            $indicator->assignments()->firstOrCreate(
                ['centre_id' => null],
                ['required' => true, 'is_demo' => (bool) $indicator->is_demo],
            );
            $this->pendingIndicatorSetup = [];

            return $indicator;
        }

        if ($indicator->assignments()->whereNull('centre_id')->where(function ($query): void {
            $query->whereHas('entries')->orWhereHas('targets');
        })->exists()) {
            throw ValidationException::withMessages([
                'reporting_scope' => 'The reporting level cannot be changed after targets or entries exist. Create a new indicator instead.',
            ]);
        }
        $indicator->assignments()->whereNull('centre_id')->delete();

        $indicator->centres()->sync($centreIds->all());
        $this->pendingIndicatorSetup = [];

        return $indicator;
    }
}
