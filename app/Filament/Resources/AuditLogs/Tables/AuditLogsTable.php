<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('Changed by')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Record type')
                    ->formatStateUsing(fn (string $state): string => str(class_basename($state))->headline()->toString()),
                TextColumn::make('subject_id')
                    ->label('Record ID')
                    ->numeric(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Changed by')
                    ->relationship('actor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('action')
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->map(fn (string $action): string => str($action)->replace('_', ' ')->headline()->toString())->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
