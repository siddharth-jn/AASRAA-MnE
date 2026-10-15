<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\IndicatorDefinitions\Schemas\IndicatorDefinitionForm;
use App\Filament\Resources\IndicatorTargets\Schemas\IndicatorTargetForm;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Services\IndicatorService;
use App\Services\TargetService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class IndicatorsRelationManager extends RelationManager
{
    protected static string $relationship = 'indicators';

    protected static ?string $title = 'Project indicators';

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
                    ->label('Centres')
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
                    ->mutateDataUsing(fn (array $data): array => $this->prepareIndicatorData($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->prepareIndicatorData($data)),
                Action::make('setTarget')
                    ->label('Set target')
                    ->icon('heroicon-o-chart-bar-square')
                    ->modalHeading(fn (IndicatorDefinition $record): string => 'Set target · '.$record->name)
                    ->modalSubmitActionLabel('Save target')
                    ->schema(fn (IndicatorDefinition $record): array => IndicatorTargetForm::components($record->id))
                    ->action(function (IndicatorDefinition $record, array $data): void {
                        abort_unless($record->assignments()->whereKey($data['indicator_assignment_id'])->exists(), 403);

                        IndicatorTarget::create(app(TargetService::class)->prepareAndValidate($data));
                    })
                    ->visible(fn (IndicatorDefinition $record): bool => $record->target_tracking && $record->assignments()->exists()),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareIndicatorData(array $data): array
    {
        $data['project_id'] = $this->getOwnerRecord()->getKey();

        return app(IndicatorService::class)->prepareAndValidate($data);
    }
}
