<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')
                    ->label('Recorded at')
                    ->dateTime(),
                TextEntry::make('actor.name')
                    ->label('Changed by')
                    ->placeholder('System'),
                TextEntry::make('action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString()),
                TextEntry::make('subject_type')
                    ->label('Record type')
                    ->formatStateUsing(fn (string $state): string => str(class_basename($state))->headline()->toString()),
                TextEntry::make('subject_id')
                    ->label('Record ID'),
                KeyValueEntry::make('changes.before')
                    ->label('Before')
                    ->keyLabel('Field')
                    ->valueLabel('Previous value')
                    ->columnSpanFull(),
                KeyValueEntry::make('changes.after')
                    ->label('After')
                    ->keyLabel('Field')
                    ->valueLabel('New value')
                    ->columnSpanFull(),
            ]);
    }
}
