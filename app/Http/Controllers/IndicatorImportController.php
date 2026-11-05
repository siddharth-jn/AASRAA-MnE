<?php

namespace App\Http\Controllers;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Models\Centre;
use App\Models\IndicatorImportBatch;
use App\Models\Project;
use App\Services\IndicatorSpreadsheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IndicatorImportController extends Controller
{
    public function downloadTemplate(Request $request, IndicatorSpreadsheetService $spreadsheets): StreamedResponse
    {
        Gate::authorize('configure');
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'scope' => ['required', 'in:project,programme'],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
        ]);
        $project = Project::query()->where('active', true)->findOrFail($data['project_id']);
        $centre = $this->centre($project, $data['scope'], $data['centre_id'] ?? null);

        return $this->download($project, $centre, $data['scope'], $spreadsheets);
    }

    public function template(Request $request, Project $project, IndicatorSpreadsheetService $spreadsheets): StreamedResponse
    {
        Gate::authorize('configure');
        abort_unless($project->active, 404);
        $data = $request->validate([
            'scope' => ['required', 'in:project,programme'],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
        ]);
        $centre = $this->centre($project, $data['scope'], $data['centre_id'] ?? null);

        return $this->download($project, $centre, $data['scope'], $spreadsheets);
    }

    public function upload(Request $request, IndicatorSpreadsheetService $spreadsheets): RedirectResponse
    {
        Gate::authorize('configure');
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'scope' => ['required', 'in:project,programme'],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
            'effective_from' => ['required', 'date_format:Y-m'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);
        $project = Project::query()->where('active', true)->findOrFail($data['project_id']);
        $centre = $this->centre($project, $data['scope'], $data['centre_id'] ?? null);
        $batch = $spreadsheets->stage(
            $request->user(),
            $project,
            $centre,
            $data['scope'],
            $data['effective_from'],
            $data['file'],
        );

        return redirect(IndicatorDefinitionResource::getUrl('import', ['batch' => $batch->id]))
            ->with('success', 'The spreadsheet was validated. Review every row before confirming the import.');
    }

    public function confirm(Request $request, IndicatorImportBatch $batch, IndicatorSpreadsheetService $spreadsheets): RedirectResponse
    {
        Gate::authorize('configure');
        $request->validate(['confirmation' => ['accepted']]);
        $spreadsheets->confirm($batch);

        return redirect(IndicatorDefinitionResource::getUrl('index'))
            ->with('success', 'The reviewed indicators and project assignments were created.');
    }

    private function download(Project $project, ?Centre $centre, string $reportingScope, IndicatorSpreadsheetService $spreadsheets): StreamedResponse
    {
        $scopeName = $centre?->name ?? $project->name.' programme';

        return response()->streamDownload(
            fn () => $spreadsheets->write($project, $centre, $reportingScope, 'php://output'),
            'aasraa-indicators-'.str($scopeName)->slug().'.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function centre(Project $project, string $reportingScope, mixed $centreId): ?Centre
    {
        if ($reportingScope === 'programme') {
            if (filled($centreId)) {
                throw ValidationException::withMessages(['centre_id' => 'Programme-level indicators must not be assigned to a project.']);
            }

            return null;
        }

        $centre = $project->centres()
            ->where('centres.active', true)
            ->find($centreId);
        if ($centre === null) {
            throw ValidationException::withMessages(['centre_id' => 'Choose an active project assigned to the selected programme.']);
        }

        return $centre;
    }
}
