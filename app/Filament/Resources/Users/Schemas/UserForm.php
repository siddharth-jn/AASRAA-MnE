<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Select::make('role')
                    ->options(array_combine(config('impact.roles'), config('impact.roles')))
                    ->required()
                    ->default('VIEWER'),
                Select::make('projects')
                    ->relationship('projects', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Select::make('centres')
                    ->relationship('centres', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Toggle::make('active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
