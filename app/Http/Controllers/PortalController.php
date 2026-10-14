<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\Project;
use App\Services\CompletenessService;
use App\Services\ReportingPeriodService;
use App\Services\ReportingService;
use App\Services\ScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function dashboard(Request $request, ScopeService $scope, CompletenessService $completeness, ReportingService $reports, ReportingPeriodService $periods): View
    {
        $period = $periods->fromRequest($request);
        $summary = $completeness->summary($request->user(), $period['data_start'], $period['data_end']);
        $entryQuery = $scope->entries($request->user())->whereBetween('period', [$period['data_start'], $period['data_end']]);
        $entries = (clone $entryQuery)->get();
        $reportingCentres = (clone $entryQuery)
            ->join('indicator_assignments', 'indicator_entries.indicator_assignment_id', '=', 'indicator_assignments.id')
            ->distinct()
            ->count('indicator_assignments.centre_id');
        $reportRows = $reports->report($request->user(), $period['data_start'], $period['data_end']);
        $projects = $scope->projects($request->user())->where('active', true)->orderBy('name')->get();

        $projectPerformance = $projects->map(function (Project $project) use ($request, $completeness, $period): array {
            $projectSummary = $completeness->summary($request->user(), $period['data_start'], $period['data_end'], $project->id);

            return ['project' => $project, 'summary' => $projectSummary];
        });

        return view('dashboard', [
            'period' => $period,
            'stats' => [
                'projects' => $projects->count(),
                'centres' => $scope->centres($request->user())->where('active', true)->count(),
                'reporting_centres' => $reportingCentres,
                'completion' => $summary['rate'],
                'pending' => $summary['pending'],
                'approved' => $summary['approved'],
            ],
            'completeness' => $summary,
            'statuses' => $entries->countBy('status'),
            'recentEntries' => $scope->entries($request->user())->with(['assignment.indicator.project', 'assignment.centre', 'currentRevision'])->latest('updated_at')->limit(8)->get(),
            'recentApproved' => $scope->entries($request->user())->where('status', 'APPROVED')->with(['assignment.indicator.project', 'assignment.centre', 'approvedRevision'])->latest('updated_at')->limit(5)->get(),
            'keyOutcomes' => $reportRows->filter(fn (array $row): bool => in_array($row['indicator']->category, ['Outcome', 'KPI'], true))->take(6),
            'projectPerformance' => $projectPerformance,
        ]);
    }

    public function projects(Request $request, ScopeService $scope): View
    {
        return view('projects.index', ['projects' => $scope->projects($request->user())->withCount(['centres', 'indicators'])->with('programme')->paginate(12)]);
    }

    public function project(Request $request, Project $project, ScopeService $scope, CompletenessService $completeness, ReportingService $reports, ReportingPeriodService $periods): View
    {
        abort_unless($scope->projects($request->user())->whereKey($project)->exists(), 403);
        $period = $periods->fromRequest($request);
        $centreId = $request->integer('centre') ?: null;
        if ($centreId) {
            abort_unless($scope->centres($request->user())->whereKey($centreId)->whereHas('projects', fn (Builder $query) => $query->whereKey($project->id))->exists(), 403);
        }

        $project->load(['programme', 'lead', 'centres' => fn ($query) => $query->whereIn('centres.id', $scope->centres($request->user())->select('centres.id'))]);
        $indicatorQuery = $project->indicators()->where('active', true)->withCount('assignments');
        if ($request->filled('category')) {
            $indicatorQuery->where('category', $request->string('category')->value());
        }
        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->trim()->value().'%';
            $indicatorQuery->where(fn ($query) => $query->where('name', 'like', $search)->orWhere('code', 'like', $search));
        }

        return view('projects.show', [
            'project' => $project,
            'period' => $period,
            'indicators' => $indicatorQuery->orderBy('category')->orderBy('name')->get(),
            'rows' => $centreId
                ? $reports->report($request->user(), $period['data_start'], $period['data_end'], $project->id, $centreId)
                : $reports->consolidatedReport($request->user(), $period['data_start'], $period['data_end'], $project->id),
            'completeness' => $completeness->summary($request->user(), $period['data_start'], $period['data_end'], $project->id, $centreId),
        ]);
    }

    public function centres(Request $request, ScopeService $scope): View
    {
        return view('centres.index', ['centres' => $scope->centres($request->user())->withCount('projects')->paginate(15)]);
    }

    public function centre(Request $request, Centre $centre, ScopeService $scope, CompletenessService $completeness, ReportingPeriodService $periods): View
    {
        abort_unless($scope->centres($request->user())->whereKey($centre)->exists(), 403);
        $period = $periods->fromRequest($request);
        $projectId = $request->integer('project') ?: null;
        if ($projectId) {
            abort_unless($scope->projects($request->user())->whereKey($projectId)->whereHas('centres', fn (Builder $query) => $query->whereKey($centre->id))->exists(), 403);
        }

        $centre->load(['projects' => fn ($query) => $query->whereIn('projects.id', $scope->projects($request->user())->select('projects.id')), 'users']);
        $entries = $scope->entries($request->user())
            ->whereHas('assignment', function (Builder $query) use ($centre, $projectId): void {
                $query->where('centre_id', $centre->id);
                if ($projectId) {
                    $query->whereHas('indicator', fn (Builder $indicatorQuery) => $indicatorQuery->where('project_id', $projectId));
                }
            })
            ->whereBetween('period', [$period['data_start'], $period['data_end']])
            ->with(['assignment.indicator.project', 'approvedRevision'])
            ->orderByDesc('status')
            ->paginate(50)
            ->withQueryString();

        return view('centres.show', [
            'centre' => $centre,
            'entries' => $entries,
            'period' => $period,
            'completeness' => $completeness->summary($request->user(), $period['data_start'], $period['data_end'], $projectId, $centre->id),
        ]);
    }
}
