<?php

namespace App\Http\Controllers;

use App\Services\ReportingService;
use App\Services\ScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportingService $reports, ScopeService $scope): View
    {
        [$start, $end] = $this->period($request);

        return view('reports.index', ['rows' => $reports->report($request->user(), $start, $end, $request->integer('project') ?: null, $request->integer('centre') ?: null), 'start' => $start, 'end' => $end, 'projects' => $scope->projects($request->user())->orderBy('name')->get(), 'centres' => $scope->centres($request->user())->orderBy('name')->get()]);
    }

    public function export(Request $request, ReportingService $reports): StreamedResponse
    {
        [$start, $end] = $this->period($request);
        $rows = $reports->report($request->user(), $start, $end, $request->integer('project') ?: null, $request->integer('centre') ?: null);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Project', 'Centre', 'Indicator', 'Category', 'Achievement', 'Unit', 'Status']);
            foreach ($rows as $row) {
                fputcsv($output, [$row['project']->name, $row['centre']->name, $row['indicator']->name, $row['indicator']->category, is_array($row['achievement']) ? json_encode($row['achievement']) : $row['achievement'], $row['indicator']->unit, $row['status']]);
            }
            fclose($output);
        }, "aasra-impact-{$start->format('Ymd')}-{$end->format('Ymd')}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(Request $request): array
    {
        $start = CarbonImmutable::parse($request->input('start', '2026-07-01'))->startOfDay();
        $end = CarbonImmutable::parse($request->input('end', '2026-09-30'))->endOfDay();
        abort_if($end->lt($start), 422, 'End date must be after start date.');

        return [$start, $end];
    }
}
