<?php

namespace App\Filament\Resources\IndicatorTargets;

use App\Filament\Resources\IndicatorTargets\Pages\EditIndicatorTarget;
use App\Filament\Resources\IndicatorTargets\Pages\ListIndicatorTargets;
use App\Filament\Resources\IndicatorTargets\Pages\PlanIndicatorTargets;
use App\Filament\Resources\IndicatorTargets\Schemas\IndicatorTargetForm;
use App\Filament\Resources\IndicatorTargets\Tables\IndicatorTargetsTable;
use App\Models\IndicatorTarget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IndicatorTargetResource extends Resource
{
    protected static ?string $model = IndicatorTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Indicator targets';

    protected static ?string $modelLabel = 'indicator target';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return IndicatorTargetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IndicatorTargetsTable::configure($table);
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
            'index' => ListIndicatorTargets::route('/'),
            'set' => PlanIndicatorTargets::route('/set'),
            'edit' => EditIndicatorTarget::route('/{record}/edit'),
        ];
    }
}
