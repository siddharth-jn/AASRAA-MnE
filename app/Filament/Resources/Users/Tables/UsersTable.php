<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('role')
                    ->formatStateUsing(fn (string $state): string => str_replace(['Program', 'Centre'], ['Programme', 'Project'], str($state)->lower()->headline()->toString()))
                    ->searchable(),
                IconColumn::make('active')
                    ->boolean(),
                IconColumn::make('is_demo')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(collect(config('impact.roles'))->mapWithKeys(fn (string $role): array => [$role => str($role)->lower()->headline()->toString()])->all()),
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
