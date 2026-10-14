<?php

namespace App\Http\Controllers;

use App\Services\CompletenessService;
use App\Services\ReportingPeriodService;
use App\Services\ReportingService;
use App\Services\ScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportingService $reports, ReportingPeriodService $periods, CompletenessService $completeness, ScopeService $scope): View
    {
        $period = $periods->fromRequest($request);
        [$projectId, $centreId] = $this->scopeFilters($request, $scope);
        $projects = $scope->projects($request->user())->orderBy('name')->get();
        $centres = $scope->centres($request->user())
            ->when($projectId, fn (Builder $query): Builder => $query->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId)))
            ->orderBy('name')
            ->get();

        return view('reports.index', [
            'rows' => $reports->report($request->user(), $period['data_start'], $period['data_end'], $projectId, $centreId),
            'period' => $period,
            'completeness' => $completeness->summary($request->user(), $period['data_start'], $period['data_end'], $projectId, $centreId),
            'projects' => $projects,
            'centres' => $centres,
            'selectedProjectId' => $projectId,
            'selectedCentreId' => $centreId,
        ]);
    }

    public function export(Request $request, ReportingService $reports, ReportingPeriodService $periods, ScopeService $scope): StreamedResponse
    {
        $period = $periods->fromRequest($request);
        [$projectId, $centreId] = $this->scopeFilters($request, $scope);
        $rows = $reports->report($request->user(), $period['data_start'], $period['data_end'], $projectId, $centreId);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Project', 'Centre', 'Indicator', 'Category', 'Target', 'Achievement', 'Variance', 'Achievement %', 'Unit', 'Performance', 'Approval']);
            foreach ($rows as $row) {
                fputcsv($output, $this->exportRow($row));
            }
            fclose($output);
        }, "aasra-impact-{$period['data_start']->format('Ymd')}-{$period['data_end']->format('Ymd')}.csv", ['Content-Type' => 'text/csv']);
    }

    public function excel(Request $request, ReportingService $reports, ReportingPeriodService $periods, ScopeService $scope): StreamedResponse
    {
        $period = $periods->fromRequest($request);
        [$projectId, $centreId] = $this->scopeFilters($request, $scope);
        $rows = $reports->report($request->user(), $period['data_start'], $period['data_end'], $projectId, $centreId);

        return response()->streamDownload(function () use ($rows): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Approved results');
            $sheet->fromArray(['Project', 'Centre', 'Indicator', 'Category', 'Target', 'Achievement', 'Variance', 'Achievement %', 'Unit', 'Performance', 'Approval'], null, 'A1');
            $sheet->getStyle('A1:K1')->getFont()->setBold(true);
            foreach ($rows->values() as $index => $row) {
                $sheet->fromArray($this->exportRow($row), null, 'A'.($index + 2));
            }
            foreach (range('A', 'K') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, "aasra-impact-{$period['data_start']->format('Ymd')}-{$period['data_end']->format('Ymd')}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{?int, ?int} */
    private function scopeFilters(Request $request, ScopeService $scope): array
    {
        $projectId = $request->integer('project') ?: null;
        $centreId = $request->integer('centre') ?: null;

        if ($projectId) {
            abort_unless($scope->projects($request->user())->whereKey($projectId)->exists(), 403);
        }

        if ($centreId) {
            $centreQuery = $scope->centres($request->user())->whereKey($centreId);
            if ($projectId) {
                $centreQuery->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId));
            }
            abort_unless($centreQuery->exists(), 422, 'The selected centre is not assigned to the selected project.');
        }

        return [$projectId, $centreId];
    }

    /** @param array<string, mixed> $row
     * @return array<int, mixed>
     */
    private function exportRow(array $row): array
    {
        return [
            $row['project']->name,
            $row['centre']->name,
            $row['indicator']->name,
            $row['indicator']->category,
            $row['target_display'],
            is_array($row['achievement']) ? json_encode($row['achievement']) : $row['achievement'],
            $row['variance'],
            $row['achievement_percentage'],
            $row['indicator']->unit,
            $row['performance_status'],
            $row['status'],
        ];
    }
}
