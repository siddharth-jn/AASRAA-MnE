<?php

namespace App\Filament\Resources\Donors\Pages;

use App\Filament\Resources\Donors\DonorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDonors extends ListRecords
{
    protected static string $resource = DonorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add donor'),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Add and maintain donor names here. Assign donors, dates, and primary status from the relevant Project.';
    }
}
