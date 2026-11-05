<?php

namespace App\Filament\Resources\IndicatorDefinitions\Pages;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Models\Centre;
use App\Models\IndicatorImportBatch;
use App\Models\IndicatorImportRow;
use App\Models\Project;
use App\Services\IndicatorSpreadsheetService;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

class ImportIndicatorDefinitions extends Page
{
    protected static string $resource = IndicatorDefinitionResource::class;

    protected string $view = 'filament.resources.indicator-definitions.pages.import-indicator-definitions';

    public ?int $batchId = null;

    public ?int $selectedProgrammeId = null;

    public string $selectedScope = 'project';

    public ?int $selectedCentreId = null;

    public function mount(): void
    {
        $this->batchId = request()->integer('batch') ?: null;
        $this->selectedProgrammeId = request()->integer('programme') ?: null;
        $this->selectedScope = in_array(request()->string('scope')->toString(), ['project', 'programme'], true)
            ? request()->string('scope')->toString()
            : 'project';
        $this->selectedCentreId = request()->integer('centre') ?: null;

        if ($this->batchId !== null) {
            $batch = IndicatorImportBatch::query()->with('rows')->find($this->batchId);
            $this->selectedProgrammeId ??= $batch?->project_id;

            if (! request()->has('scope')) {
                $firstRow = $batch?->rows->sortBy('row_number')->first();
                $this->selectedScope = $firstRow?->proposed_indicator['reporting_scope'] ?? 'project';
                $this->selectedCentreId = collect($firstRow?->centre_ids)->map(fn (mixed $centreId): int => (int) $centreId)->first();
            }
        }
    }

    public function getTitle(): string
    {
        return 'Import indicators';
    }

    /** @return Collection<int, Project> */
    public function programmes(): Collection
    {
        return Project::query()->where('active', true)->orderBy('name')->get();
    }

    public function selectedProgramme(): ?Project
    {
        if ($this->selectedProgrammeId === null) {
            return null;
        }

        return Project::query()
            ->where('active', true)
            ->with(['centres' => fn ($query) => $query->where('centres.active', true)->orderBy('name')])
            ->find($this->selectedProgrammeId);
    }

    public function selectedCentre(): ?Centre
    {
        if ($this->selectedScope !== 'project' || $this->selectedCentreId === null) {
            return null;
        }

        $programme = $this->selectedProgramme();

        return $programme?->centres->firstWhere('id', $this->selectedCentreId);
    }

    public function batch(): ?IndicatorImportBatch
    {
        if (! $this->batchId) {
            return null;
        }

        return IndicatorImportBatch::query()
            ->with(['project.centres', 'user', 'rows'])
            ->findOrFail($this->batchId);
    }

    public function validationMessage(IndicatorImportRow $row): string
    {
        return app(IndicatorSpreadsheetService::class)->validationMessage(
            $row->error,
            $row->row_number,
            $row->source ?? [],
        );
    }

    /** @return Collection<int, IndicatorImportBatch> */
    public function recentBatches(): Collection
    {
        return IndicatorImportBatch::query()
            ->with(['project', 'user'])
            ->withCount('rows')
            ->latest()
            ->limit(20)
            ->get();
    }
}
