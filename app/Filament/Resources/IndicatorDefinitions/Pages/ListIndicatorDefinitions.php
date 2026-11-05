<?php

namespace App\Filament\Resources\IndicatorDefinitions\Pages;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListIndicatorDefinitions extends ListRecords
{
    protected static string $resource = IndicatorDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importIndicators')
                ->label('Import indicators')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(IndicatorDefinitionResource::getUrl('import')),
            CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Create and manage indicator definitions, project assignments, validation rules, aggregation, evidence, and approvals. Use filters to narrow the list.';
    }
}
