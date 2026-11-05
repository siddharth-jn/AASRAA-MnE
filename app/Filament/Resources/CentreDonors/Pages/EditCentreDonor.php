<?php

namespace App\Filament\Resources\CentreDonors\Pages;

use App\Filament\Resources\CentreDonors\CentreDonorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCentreDonor extends EditRecord
{
    protected static string $resource = CentreDonorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
