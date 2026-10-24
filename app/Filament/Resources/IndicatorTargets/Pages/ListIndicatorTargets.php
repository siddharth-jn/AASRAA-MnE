<?php

namespace App\Filament\Resources\IndicatorTargets\Pages;

use App\Filament\Resources\IndicatorTargets\IndicatorTargetResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListIndicatorTargets extends ListRecords
{
    protected static string $resource = IndicatorTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setTargets')
                ->label('Set targets')
                ->url(IndicatorTargetResource::getUrl('set')),
        ];
    }
}
