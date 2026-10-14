<?php

namespace App\Http\Controllers;

use App\Models\IndicatorDefinition;
use App\Models\Project;
use App\Services\ReportingService;
use App\Services\ScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KpiController extends Controller
{
    public function show(Request $request, Project $project, IndicatorDefinition $indicator, ScopeService $scope, ReportingService $reports): View
    {
        abort_unless($indicator->project_id === $project->id && $scope->projects($request->user())->whereKey($project)->exists(), 403);
        $centreId = $request->integer('centre') ?: null;
        if ($centreId) {
            abort_unless($scope->centres($request->user())->whereKey($centreId)->exists() && $indicator->centres()->whereKey($centreId)->exists(), 403);
        }

        $end = CarbonImmutable::parse($request->input('end', '2026-09-30'))->endOfMonth();
        $start = CarbonImmutable::parse($request->input('start', $end->subMonths(5)->startOfMonth()->toDateString()))->startOfMonth();
        abort_if($end->lt($start), 422, 'End date must be after start date.');

        $entries = $scope->entries($request->user())
            ->whereHas('assignment', function (Builder $query) use ($indicator, $centreId): void {
                $query->where('indicator_definition_id', $indicator->id);
                if ($centreId) {
                    $query->where('centre_id', $centreId);
                }
            })
            ->whereBetween('period', [$start, $end])
            ->with(['assignment.centre', 'approvedRevision.evidence', 'revisions.user', 'revisions.evidence', 'revisions.actions.user'])
            ->orderBy('period')
            ->get();

        return view('kpis.show', [
            'project' => $project,
            'indicator' => $indicator->load('centres'),
            'start' => $start,
            'end' => $end,
            'entries' => $entries,
            'rows' => $reports->report($request->user(), $start, $end, $project->id, $centreId, $indicator->id),
        ]);
    }
}
