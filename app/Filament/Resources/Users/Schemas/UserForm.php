<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Centre;
use App\Models\Project;
use App\Support\PasswordPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                    ->label(fn (string $operation): string => $operation === 'create' ? 'Password' : 'New password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->confirmed()
                    ->rules([PasswordPolicy::rule()])
                    ->helperText(fn (string $operation): string => $operation === 'create'
                        ? PasswordPolicy::guidance()
                        : 'Passwords are never displayed after saving. Leave this blank to keep the current password. '.PasswordPolicy::guidance())
                    ->autocomplete('new-password')
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                TextInput::make('password_confirmation')
                    ->label('Confirm password')
                    ->password()
                    ->required(fn (Get $get): bool => filled($get('password')))
                    ->autocomplete('new-password')
                    ->revealable()
                    ->dehydrated(false),
                Select::make('role')
                    ->options(array_combine(config('impact.roles'), config('impact.roles')))
                    ->required()
                    ->default('VIEWER'),
                Select::make('projects')
                    ->relationship('projects', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('centres', [])),
                Actions::make([
                    Action::make('selectAllProjects')
                        ->label('Select all projects')
                        ->link()
                        ->action(function (Set $set): void {
                            $set('projects', Project::query()->orderBy('name')->pluck('id')->all());
                            $set('centres', []);
                        }),
                    Action::make('clearProjects')
                        ->label('Clear projects')
                        ->color('gray')
                        ->link()
                        ->action(function (Set $set): void {
                            $set('projects', []);
                            $set('centres', []);
                        }),
                ])->key('projectSelectionActions'),
                Select::make('centres')
                    ->relationship(
                        'centres',
                        'name',
                        modifyQueryUsing: function (Builder $query, Get $get): Builder {
                            $projectIds = collect($get('projects'))->filter()->all();

                            return $projectIds === []
                                ? $query->whereRaw('1 = 0')
                                : $query->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereIn('projects.id', $projectIds));
                        },
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Only centres assigned to the selected projects are available.'),
                Actions::make([
                    Action::make('selectAllCentres')
                        ->label('Select all centres in selected projects')
                        ->link()
                        ->disabled(fn (Get $get): bool => collect($get('projects'))->filter()->isEmpty())
                        ->action(function (Get $get, Set $set): void {
                            $projectIds = collect($get('projects'))->filter()->all();
                            $centreIds = Centre::query()
                                ->whereHas('projects', fn (Builder $query): Builder => $query->whereIn('projects.id', $projectIds))
                                ->orderBy('name')
                                ->pluck('id')
                                ->all();

                            $set('centres', $centreIds);
                        }),
                    Action::make('clearCentres')
                        ->label('Clear centres')
                        ->color('gray')
                        ->link()
                        ->action(fn (Set $set): mixed => $set('centres', [])),
                ])->key('centreSelectionActions'),
                Toggle::make('active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
