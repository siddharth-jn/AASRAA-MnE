<?php

namespace App\Filament\Resources\IndicatorDefinitions\Pages;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Services\IndicatorService;
use Filament\Resources\Pages\CreateRecord;

class CreateIndicatorDefinition extends CreateRecord
{
    protected static string $resource = IndicatorDefinitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(IndicatorService::class)->prepareAndValidate($data);
    }
}
