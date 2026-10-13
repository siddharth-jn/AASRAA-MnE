<?php

namespace App\Filament\Resources\IndicatorDefinitions;

use App\Filament\Resources\IndicatorDefinitions\Pages\CreateIndicatorDefinition;
use App\Filament\Resources\IndicatorDefinitions\Pages\EditIndicatorDefinition;
use App\Filament\Resources\IndicatorDefinitions\Pages\ListIndicatorDefinitions;
use App\Filament\Resources\IndicatorDefinitions\Schemas\IndicatorDefinitionForm;
use App\Filament\Resources\IndicatorDefinitions\Tables\IndicatorDefinitionsTable;
use App\Models\IndicatorDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IndicatorDefinitionResource extends Resource
{
    protected static ?string $model = IndicatorDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return IndicatorDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IndicatorDefinitionsTable::configure($table);
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
            'index' => ListIndicatorDefinitions::route('/'),
            'create' => CreateIndicatorDefinition::route('/create'),
            'edit' => EditIndicatorDefinition::route('/{record}/edit'),
        ];
    }
}
