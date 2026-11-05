<?php

namespace App\Filament\Resources\CentreDonors;

use App\Filament\Resources\CentreDonors\Pages\CreateCentreDonor;
use App\Filament\Resources\CentreDonors\Pages\EditCentreDonor;
use App\Filament\Resources\CentreDonors\Pages\ListCentreDonors;
use App\Filament\Resources\CentreDonors\Schemas\CentreDonorForm;
use App\Filament\Resources\CentreDonors\Tables\CentreDonorsTable;
use App\Models\CentreDonor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CentreDonorResource extends Resource
{
    protected static ?string $model = CentreDonor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Project donors';

    protected static ?string $modelLabel = 'project donor assignment';

    protected static ?int $navigationSort = 21;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return CentreDonorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CentreDonorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCentreDonors::route('/'),
            'create' => CreateCentreDonor::route('/create'),
            'edit' => EditCentreDonor::route('/{record}/edit'),
        ];
    }
}
