<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Project;
use App\Services\ImportService;
use App\Services\ScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(Request $request, ScopeService $scope): View
    {
        Gate::authorize('import');
        $projectIds = $scope->projects($request->user())->select('projects.id');

        return view('imports.index', [
            'projects' => $scope->projects($request->user())->where('active', true)->orderBy('name')->get(),
            'batches' => ImportBatch::query()->whereIn('project_id', $projectIds)->with(['project', 'user'])->withCount('rows')->latest()->paginate(20),
        ]);
    }

    public function upload(Request $request, ImportService $imports, ScopeService $scope): RedirectResponse
    {
        Gate::authorize('import');
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:csv,xlsx', 'max:10240'],
        ]);
        $project = $scope->projects($request->user())->whereKey($data['project_id'])->firstOrFail();
        $batch = $imports->upload($request->user(), $project, $data['file']);

        return redirect()->route('imports.show', $batch)->with('success', 'Spreadsheet uploaded. Map the source columns and validate the rows.');
    }

    public function show(Request $request, ImportBatch $batch, ScopeService $scope): View
    {
        Gate::authorize('import');
        $this->authorizeBatch($request, $batch, $scope);
        $batch->load(['project', 'user'])->loadCount([
            'rows',
            'rows as valid_rows_count' => fn ($query) => $query->where('status', 'valid'),
            'rows as review_rows_count' => fn ($query) => $query->where('status', 'review'),
            'rows as invalid_rows_count' => fn ($query) => $query->where('status', 'invalid'),
            'rows as duplicate_rows_count' => fn ($query) => $query->where('status', 'duplicate'),
            'rows as imported_rows_count' => fn ($query) => $query->where('status', 'imported'),
        ]);

        return view('imports.show', [
            'batch' => $batch,
            'rows' => $batch->rows()->orderBy('row_number')->paginate(50),
        ]);
    }

    public function validateImport(Request $request, ImportBatch $batch, ImportService $imports, ScopeService $scope): RedirectResponse
    {
        Gate::authorize('import');
        $this->authorizeBatch($request, $batch, $scope);
        $columns = $batch->columns;
        $data = $request->validate([
            'mapping.centre' => ['required', Rule::in($columns)],
            'mapping.indicator' => ['required', Rule::in($columns)],
            'mapping.period' => ['required', Rule::in($columns)],
            'mapping.value' => ['nullable', Rule::in($columns)],
            'mapping.numerator' => ['nullable', Rule::in($columns)],
            'mapping.denominator' => ['nullable', Rule::in($columns)],
            'mapping.notes' => ['nullable', Rule::in($columns)],
        ]);
        $imports->validateRows($batch, $data['mapping']);

        return back()->with('success', 'Rows validated. Review errors and duplicates before confirming the import.');
    }

    public function confirm(Request $request, ImportBatch $batch, ImportService $imports, ScopeService $scope): RedirectResponse
    {
        Gate::authorize('import');
        $this->authorizeBatch($request, $batch, $scope);
        $imports->confirm($request->user(), $batch);

        return back()->with('success', 'Valid rows were imported as drafts. Records requiring evidence remain drafts until proof is attached.');
    }

    public function template(Request $request, Project $project, ScopeService $scope): StreamedResponse
    {
        Gate::authorize('import');
        abort_unless($scope->projects($request->user())->whereKey($project)->exists(), 403);
        $format = $request->input('format', 'xlsx');
        if ($format === 'csv') {
            return response()->streamDownload(function (): void {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['centre_code', 'indicator_code', 'period', 'value', 'numerator', 'denominator', 'notes']);
                fputcsv($output, ['CENTRE-CODE', 'INDICATOR-CODE', '2026-09-01', '25', '', '', 'Replace this sample row']);
                fclose($output);
            }, "{$project->code}-import-template.csv", ['Content-Type' => 'text/csv']);
        }

        return response()->streamDownload(function () use ($project): void {
            $project->load(['centres', 'indicators.centres']);
            $spreadsheet = new Spreadsheet;
            $data = $spreadsheet->getActiveSheet();
            $data->setTitle('Import data');
            $data->fromArray(['centre_code', 'indicator_code', 'period', 'value', 'numerator', 'denominator', 'notes'], null, 'A1');
            $data->fromArray(['CENTRE-CODE', 'INDICATOR-CODE', '2026-09-01', 25, null, null, 'Replace this sample row'], null, 'A2');
            $data->getStyle('A1:G1')->getFont()->setBold(true);
            $reference = $spreadsheet->createSheet();
            $reference->setTitle('Reference');
            $reference->fromArray(['Centre code', 'Centre name', 'Indicator code', 'Indicator name', 'Data type'], null, 'A1');
            $row = 2;
            foreach ($project->centres as $centre) {
                foreach ($project->indicators as $indicator) {
                    if (! $indicator->centres->contains($centre)) {
                        continue;
                    }
                    $reference->fromArray([$centre->code, $centre->name, $indicator->code, $indicator->name, $indicator->data_type], null, "A{$row}");
                    $row++;
                }
            }
            $reference->getStyle('A1:E1')->getFont()->setBold(true);
            foreach (range('A', 'G') as $column) {
                $data->getColumnDimension($column)->setAutoSize(true);
            }
            foreach (range('A', 'E') as $column) {
                $reference->getColumnDimension($column)->setAutoSize(true);
            }
            $spreadsheet->setActiveSheetIndex(0);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, "{$project->code}-import-template.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function authorizeBatch(Request $request, ImportBatch $batch, ScopeService $scope): void
    {
        abort_unless($scope->projects($request->user())->whereKey($batch->project_id)->exists(), 403);
    }
}
