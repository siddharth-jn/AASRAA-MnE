<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserAuditService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<string, mixed> */
    private array $configurationBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->is(auth()->user()) && ((! ($data['active'] ?? false)) || ($data['role'] ?? null) !== 'ADMIN')) {
            throw ValidationException::withMessages([
                'role' => 'You cannot deactivate your own account or remove your own administrator role.',
            ]);
        }

        $this->configurationBeforeSave = app(UserAuditService::class)->snapshot($this->record);

        return $data;
    }

    protected function afterSave(): void
    {
        app(UserAuditService::class)->record(auth()->user(), $this->record->refresh(), 'user_updated', $this->configurationBeforeSave);
    }
}
