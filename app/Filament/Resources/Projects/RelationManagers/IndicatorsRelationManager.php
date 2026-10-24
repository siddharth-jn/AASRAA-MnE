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
                    ->mutateDataUsing(fn (array $data): array => $this->prepareIndicatorData($data))
                    ->after(fn (IndicatorDefinition $record): IndicatorDefinition => $this->completeIndicatorSetup($record)),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareIndicatorData(array $data): array
    {
        $setupFields = [
            'target_centre_ids',
        ];
        $this->pendingIndicatorSetup = Arr::only($data, $setupFields);
        $data = Arr::except($data, $setupFields);
        $data['project_id'] = $this->getOwnerRecord()->getKey();

        return app(IndicatorService::class)->prepareAndValidate($data);
    }

    private function completeIndicatorSetup(IndicatorDefinition $indicator): IndicatorDefinition
    {
        $centreIds = collect($this->pendingIndicatorSetup['target_centre_ids'] ?? [])
            ->map(fn (mixed $centreId): int => (int) $centreId)
            ->filter()
            ->values();

        $indicator->centres()->sync($centreIds->all());
        $this->pendingIndicatorSetup = [];

        return $indicator;
    }
}
