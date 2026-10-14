<?php

namespace App\Filament\Resources\IndicatorTargets\Pages;

use App\Filament\Resources\IndicatorTargets\IndicatorTargetResource;
use App\Services\TargetService;
use Filament\Resources\Pages\EditRecord;

class EditIndicatorTarget extends EditRecord
{
    protected static string $resource = IndicatorTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(TargetService::class)->prepareAndValidate($data, $this->record);
    }
}
