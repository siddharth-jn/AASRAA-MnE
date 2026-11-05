<?php

namespace App\Filament\Resources\IndicatorDefinitions\Pages;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Services\IndicatorService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateIndicatorDefinition extends CreateRecord
{
    protected static string $resource = IndicatorDefinitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['reporting_scope'] ?? 'project') === 'programme') {
            $data['centres'] = [];
        }

        return app(IndicatorService::class)->prepareAndValidate(Arr::except($data, ['centres', 'target_centre_ids']));
    }

    protected function afterCreate(): void
    {
        if ($this->record->isProgrammeScoped()) {
            $this->record->centres()->sync([]);
            $this->record->assignments()->create([
                'centre_id' => null,
                'required' => true,
                'is_demo' => (bool) $this->record->is_demo,
            ]);
        }
    }
}
