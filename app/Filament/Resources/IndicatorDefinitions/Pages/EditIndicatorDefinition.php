<?php

namespace App\Filament\Resources\IndicatorDefinitions\Pages;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Services\IndicatorService;
use Filament\Resources\Pages\EditRecord;

class EditIndicatorDefinition extends EditRecord
{
    protected static string $resource = IndicatorDefinitionResource::class;

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
        return app(IndicatorService::class)->prepareAndValidate($data);
    }
}
