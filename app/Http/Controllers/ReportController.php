<?php

namespace App\Http\Controllers;

use App\Models\IndicatorDefinition;
use App\Services\CompletenessService;
use App\Services\DonorService;
use App\Services\ReportingPeriodService;
use App\Services\ReportingService;
use App\Services\ReportPdfService;
use App\Services\ScopeService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportingService $reports, ReportingPeriodService $periods, CompletenessService $completeness, ScopeService $scope, DonorService $donors): View
    {
        $period = $periods->fromRequest($request);
        [$projectId, $centreId, $indicatorId, $category, $donor] = $this->scopeFilters($request, $scope, $period['data_start'], $period['data_end']);
        $projects = $scope->projects($request->user())->orderBy('name')->get();
        $centres = $scope->centres($request->user())
            ->when($projectId, fn (Builder $query): Builder => $query->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId)))
            ->orderBy('name')
            ->get();

        return view('reports.index', [
            'rows' => $this->reportRows($request, $reports, $period, $projectId, $centreId, $indicatorId, $category, $donor),
            'period' => $period,
            'completeness' => $completeness->summary($request->user(), $period['data_start'], $period['data_end'], $projectId, $centreId),
            'projects' => $projects,
            'centres' => $centres,
            'selectedProjectId' => $projectId,
            'selectedCentreId' => $centreId,
            'selectedIndicatorId' => $indicatorId,
            'selectedCategory' => $category,
            'selectedDonor' => $donor,
            'selectedMode' => $request->string('mode', 'detailed')->value(),
            'selectedPerformance' => $request->string('performance')->trim()->value() ?: null,
            'indicators' => IndicatorDefinition::query()
                ->whereIn('project_id', $scope->projects($request->user())->select('projects.id'))
                ->when($projectId, fn (Builder $query, int $selectedProjectId): Builder => $query->where('project_id', $selectedProjectId))
                ->when($centreId, fn (Builder $query, int $selectedCentreId): Builder => $query->whereHas('assignments', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('centre_id', $selectedCentreId)))
                ->with('project:id,name')
                ->where('active', true)
                ->orderBy('name')
                ->get(),
            'categories' => collect(config('impact.categories')),
            'donors' => $donors->availableNames($scope->centres($request->user()), $period['data_start'], $period['data_end']),
        ]);
    }

    public function export(Request $request, ReportingService $reports, ReportingPeriodService $periods, ScopeService $scope): StreamedResponse
    {
        $period = $periods->fromRequest($request);
        [$projectId, $centreId, $indicatorId, $category, $donor] = $this->scopeFilters($request, $scope, $period['data_start'], $period['data_end']);
        $rows = $this->reportRows($request, $reports, $period, $projectId, $centreId, $indicatorId, $category, $donor);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Programme', 'Project', 'Donor', 'Indicator', 'Category', 'Target', 'Achievement', 'Difference', 'Variance %', 'Variance explanation', 'Unit', 'Performance', 'Approval']);
            foreach ($rows as $row) {
                fputcsv($output, $this->exportRow($row));
            }
            fclose($output);
        }, "aasraa-mne-{$period['data_start']->format('Ymd')}-{$period['data_end']->format('Ymd')}.csv", ['Content-Type' => 'text/csv']);
    }

    public function excel(Request $request, ReportingService $reports, ReportingPeriodService $periods, ScopeService $scope): StreamedResponse
    {
        $period = $periods->fromRequest($request);
        [$projectId, $centreId, $indicatorId, $category, $donor] = $this->scopeFilters($request, $scope, $period['data_start'], $period['data_end']);
        $rows = $this->reportRows($request, $reports, $period, $projectId, $centreId, $indicatorId, $category, $donor);

        return response()->streamDownload(function () use ($rows): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Approved results');
            $sheet->fromArray(['Programme', 'Project', 'Donor', 'Indicator', 'Category', 'Target', 'Achievement', 'Difference', 'Variance %', 'Variance explanation', 'Unit', 'Performance', 'Approval'], null, 'A1');
            $sheet->getStyle('A1:M1')->getFont()->setBold(true);
            $sheet->freezePane('C2');
            $sheet->setAutoFilter('A1:M1');
            foreach ($rows->values() as $index => $row) {
                $sheet->fromArray($this->exportRow($row), null, 'A'.($index + 2));
            }
            foreach (range('A', 'M') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, "aasraa-mne-{$period['data_start']->format('Ymd')}-{$period['data_end']->format('Ymd')}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(
        Request $request,
        ReportingService $reports,
        ReportingPeriodService $periods,
        CompletenessService $completeness,
        ScopeService $scope,
        ReportPdfService $pdf,
    ): Response {
        $period = $periods->fromRequest($request);
        [$projectId, $centreId, $indicatorId, $category, $donor] = $this->scopeFilters($request, $scope, $period['data_start'], $period['data_end']);
        $rows = $this->reportRows($request, $reports, $period, $projectId, $centreId, $indicatorId, $category, $donor);
        $projects = $scope->projects($request->user())->whereIn('id', array_filter([$projectId]))->get();
        $centres = $scope->centres($request->user())->whereIn('id', array_filter([$centreId]))->get();
        $filters = [
            'programme' => $projects->first()?->name ?? 'All programmes',
            'project' => $centres->first()?->name ?? 'All projects',
            'category' => $category ?? 'All indicator types',
            'donor' => $donor ?? 'All donors',
            'performance' => $request->filled('performance') ? str($request->string('performance')->value())->replace('_', ' ')->headline()->toString() : 'All performance statuses',
            'mode' => $request->string('mode', 'detailed')->value() === 'consolidated' ? 'Consolidated programme summary' : 'Detailed project report',
            'generated_at' => now()->format('d M Y, H:i'),
        ];
        $summary = $completeness->summary($request->user(), $period['data_start'], $period['data_end'], $projectId, $centreId);
        $contents = $pdf->render($rows, $period, $filters, $summary);
        $filename = "aasraa-mne-{$period['data_start']->format('Ymd')}-{$period['data_end']->format('Ymd')}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @return array{?int, ?int, ?int, ?string, ?string} */
    private function scopeFilters(Request $request, ScopeService $scope, CarbonInterface $start, CarbonInterface $end): array
    {
        $projectId = $request->integer('project') ?: null;
        $centreId = $request->integer('centre') ?: null;
        $indicatorId = $request->integer('indicator') ?: null;
        $category = $request->string('category')->trim()->value() ?: null;
        $donor = $request->string('donor')->trim()->value() ?: null;

        if ($projectId) {
            abort_unless($scope->projects($request->user())->whereKey($projectId)->exists(), 403);
        }

        if ($centreId) {
            $centreQuery = $scope->centres($request->user())->whereKey($centreId);
            if ($projectId) {
                $centreQuery->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId));
            }
            abort_unless($centreQuery->exists(), 422, 'The selected project is not assigned to the selected programme.');
        }

        if ($indicatorId) {
            $indicatorQuery = IndicatorDefinition::query()->whereKey($indicatorId)->whereIn('project_id', $scope->projects($request->user())->select('projects.id'));
            if ($projectId) {
                $indicatorQuery->where('project_id', $projectId);
            }
            abort_unless($indicatorQuery->exists(), 422, 'The selected indicator is not available for the selected programme.');
        }

        if ($category && ! in_array($category, config('impact.categories'), true)) {
            abort(422, 'Choose a valid indicator category.');
        }

        if ($donor) {
            abort_unless(app(DonorService::class)->availableNames($scope->centres($request->user()), $start, $end)->contains($donor), 422, 'Choose a valid donor.');
        }

        abort_unless(in_array($request->string('mode', 'detailed')->value(), ['detailed', 'consolidated'], true), 422, 'Choose a valid report view.');
        if ($request->filled('performance')) {
            abort_unless(in_array($request->string('performance')->value(), ['on_track', 'needs_attention', 'overachiever', 'not_assessed'], true), 422, 'Choose a valid performance status.');
        }

        return [$projectId, $centreId, $indicatorId, $category, $donor];
    }

    /** @param array<string, mixed> $row
     * @return array<int, mixed>
     */
    private function exportRow(array $row): array
    {
        return [
            $row['project']->name,
            $row['centre']?->name ?? (($row['scope'] ?? 'project') === 'programme' ? 'Programme level' : 'All projects ('.$row['centre_count'].')'),
            $row['donor_display'] ?? 'Not specified',
            $row['indicator']->name,
            $row['indicator']->category,
            $row['target_display'],
            is_array($row['achievement']) ? json_encode($row['achievement']) : $row['achievement'],
            $row['variance'],
            $row['achievement_percentage'],
            $row['variance_explanations']->join(' | '),
            $row['indicator']->unit,
            $row['performance_status'],
            $row['status'],
        ];
    }

    /** @param array<string, mixed> $period
     * @return Collection<int, array<string, mixed>>
     */
    private function reportRows(
        Request $request,
        ReportingService $reports,
        array $period,
        ?int $projectId,
        ?int $centreId,
        ?int $indicatorId,
        ?string $category,
        ?string $donor,
    ): Collection {
        $mode = $request->string('mode', 'detailed')->value();
        $rows = $mode === 'consolidated' && $centreId === null
            ? $reports->consolidatedReport($request->user(), $period['data_start'], $period['data_end'], $projectId, $indicatorId, $category, $donor)
            : $reports->report($request->user(), $period['data_start'], $period['data_end'], $projectId, $centreId, $indicatorId, $category, $donor);

        return $reports->filterByPerformance($rows, $request->string('performance')->trim()->value() ?: null);
    }
}
