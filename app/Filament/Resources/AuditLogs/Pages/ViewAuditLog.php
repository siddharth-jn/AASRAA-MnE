<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\User;
use App\Services\UserAuditService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restoreUserConfiguration')
                ->label('Restore this user configuration')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('The user role, active status, programme assignments, and project assignments will be restored to the Before values shown below. A new recovery audit record will be created.')
                ->visible(fn (): bool => $this->record->subject_type === User::class && is_array($this->record->changes['before'] ?? null))
                ->action(function (): void {
                    app(UserAuditService::class)->restore($this->record, auth()->user());
                    Notification::make()->success()->title('User configuration restored')->send();
                    $this->redirect(AuditLogResource::getUrl('index'));
                }),
        ];
    }
}
