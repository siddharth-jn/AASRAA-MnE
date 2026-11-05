<?php

namespace App\Filament\Resources\Centres\RelationManagers;

use App\Filament\Resources\CentreDonors\Schemas\CentreDonorForm;
use App\Filament\Resources\CentreDonors\Tables\CentreDonorsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DonorAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'donorAssignments';

    protected static ?string $title = 'Donor assignments';

    public function form(Schema $schema): Schema
    {
        return CentreDonorForm::configure($schema, includeProject: false);
    }

    public function table(Table $table): Table
    {
        return CentreDonorsTable::configure($table, showProject: false)
            ->recordTitleAttribute('donor.name')
            ->headerActions([
                CreateAction::make()
                    ->label('Assign donor')
                    ->createAnother(false),
            ]);
    }
}
