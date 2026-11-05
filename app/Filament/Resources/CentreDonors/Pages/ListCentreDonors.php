<?php

namespace App\Filament\Resources\CentreDonors\Pages;

use App\Filament\Resources\CentreDonors\CentreDonorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCentreDonors extends ListRecords
{
    protected static string $resource = CentreDonorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
