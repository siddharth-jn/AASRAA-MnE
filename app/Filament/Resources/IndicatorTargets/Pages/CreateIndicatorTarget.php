<?php

namespace App\Filament\Resources\IndicatorTargets\Pages;

use App\Filament\Resources\IndicatorTargets\IndicatorTargetResource;
use App\Services\TargetService;
use Filament\Resources\Pages\CreateRecord;

class CreateIndicatorTarget extends CreateRecord
{
    protected static string $resource = IndicatorTargetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(TargetService::class)->prepareAndValidate($data);
    }
}
