<?php

namespace App\Filament\Resources\IndicatorTargets\Pages;

use App\Filament\Resources\IndicatorTargets\IndicatorTargetResource;
use App\Models\IndicatorAssignment;
use App\Services\TargetService;
use Filament\Resources\Pages\EditRecord;

class EditIndicatorTarget extends EditRecord
{
    protected static string $resource = IndicatorTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $indicator = $this->record->assignment()->with('indicator')->firstOrFail()->indicator;
        $data['target_type'] = app(TargetService::class)->typeForIndicator($indicator);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['indicator_assignment_id'] = $this->record->indicator_assignment_id;
        $data['period'] = $this->record->period->toDateString();
        $data['period_type'] = IndicatorAssignment::query()->with('indicator')->findOrFail($this->record->indicator_assignment_id)->indicator->frequency;

        return app(TargetService::class)->prepareAndValidate($data, $this->record);
    }
}
