<?php

namespace App\Filament\Resources\IndicatorDefinitions\Pages;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Models\IndicatorDefinition;
use App\Services\IndicatorReplacementService;
use App\Services\IndicatorService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditIndicatorDefinition extends EditRecord
{
    protected static string $resource = IndicatorDefinitionResource::class;

    /** @var array<int, int> */
    private array $pendingCentreIds = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createReplacement')
                ->label('Create replacement')
                ->icon('heroicon-o-document-duplicate')
                ->color('warning')
                ->visible(fn (): bool => $this->record->hasHistoricalData())
                ->modalHeading('Archive and replace this indicator')
                ->modalDescription('The existing indicator and all of its targets, entries, evidence, and reports will remain unchanged. A new active indicator will copy the configuration and project assignments, but it will start without targets or entries.')
                ->modalSubmitActionLabel('Archive and create replacement')
                ->schema([
                    TextInput::make('effective_from')
                        ->label('Replacement starts from')
                        ->type('month')
                        ->default(fn (): string => app(IndicatorReplacementService::class)->earliestMonth($this->record))
                        ->helperText('The first available month after the latest existing target or entry is selected automatically.')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $replacement = app(IndicatorReplacementService::class)->replace($this->record, $data['effective_from']);
                    Notification::make()
                        ->success()
                        ->title('Replacement indicator created')
                        ->body('The original was archived. Set targets for the replacement before data entry begins.')
                        ->send();
                    $this->redirect(IndicatorDefinitionResource::getUrl('edit', ['record' => $replacement]));
                }),
            DeleteAction::make()
                ->modalHeading('Delete unused indicator?')
                ->modalDescription('This permanently deletes the indicator definition and its project assignments. This cannot be undone.')
                ->modalSubmitActionLabel('Delete indicator')
                ->disabled(fn (): bool => $this->record->hasHistoricalData())
                ->tooltip(fn (): ?string => $this->record->hasHistoricalData()
                    ? 'This indicator has targets or entries. Archive it instead to preserve reporting history.'
                    : null)
                ->using(fn (IndicatorDefinition $record): bool => $record->deleteIfUnused()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingCentreIds = collect($data['centres'] ?? $data['target_centre_ids'] ?? $this->record->centres()->pluck('centres.id'))
            ->map(fn (mixed $centreId): int => (int) $centreId)
            ->filter()
            ->values()
            ->all();
        $newScope = $data['reporting_scope'] ?? $this->record->reporting_scope;
        $currentCentreIds = $this->record->centres()->pluck('centres.id')->map(fn (mixed $centreId): int => (int) $centreId)->sort()->values()->all();
        $pendingCentreIds = collect($this->pendingCentreIds)->sort()->values()->all();
        if ($this->record->hasHistoricalData()
            && ($newScope !== $this->record->reporting_scope || $pendingCentreIds !== $currentCentreIds)) {
            throw ValidationException::withMessages([
                'centres' => 'Project assignments and reporting level are locked because targets or entries exist. Archive this indicator and create a replacement instead.',
            ]);
        }
        if ($newScope === 'programme') {
            $data['centres'] = [];
        }

        $data = array_merge(
            $this->record->only(IndicatorDefinition::HISTORICAL_CONFIGURATION_FIELDS),
            Arr::except($data, ['centres', 'target_centre_ids']),
        );

        if ($this->record->hasHistoricalData()) {
            return $data;
        }

        return app(IndicatorService::class)->prepareAndValidate($data);
    }

    protected function afterSave(): void
    {
        if ($this->record->isProgrammeScoped()) {
            $this->record->centres()->sync([]);
            $this->record->assignments()->whereNotNull('centre_id')->delete();
            $this->record->assignments()->firstOrCreate(
                ['centre_id' => null],
                ['required' => true, 'is_demo' => (bool) $this->record->is_demo],
            );

            return;
        }

        $this->record->assignments()->whereNull('centre_id')->delete();
        $this->record->centres()->sync($this->pendingCentreIds);
    }
}
