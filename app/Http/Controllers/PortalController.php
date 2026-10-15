<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\EvidenceFile;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Services\CompletenessService;
use App\Services\ReportingPeriodService;
use App\Services\ReportingService;
use App\Services\ScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function dashboard(Request $request, ScopeService $scope): View
    {
        return view('dashboard', [
            'stats' => [
                'projects' => $scope->projects($request->user())->where('active', true)->count(),
                'centres' => $scope->centres($request->user())->where('active', true)->count(),
                'pending' => $scope->entries($request->user())->where('status', 'SUBMITTED')->count(),
            ],
            'recentEntries' => $scope->entries($request->user())->with(['assignment.indicator.project', 'assignment.centre', 'currentRevision'])->latest('updated_at')->limit(8)->get(),
            'recentApproved' => $scope->entries($request->user())->where('status', 'APPROVED')->with(['assignment.indicator.project', 'assignment.centre', 'approvedRevision'])->latest('updated_at')->limit(5)->get(),
        ]);
    }

    public function projects(Request $request, ScopeService $scope): View
    {
        return view('projects.index', ['projects' => $scope->projects($request->user())->withCount(['centres', 'indicators'])->with('programme')->paginate(12)]);
    }

    public function project(Request $request, Project $project, ScopeService $scope, CompletenessService $completeness, ReportingService $reports, ReportingPeriodService $periods): View
    {
        abort_unless($scope->projects($request->user())->whereKey($project)->exists(), 403);
        $sectionCategories = [
            'inputs' => 'Input',
            'activities' => 'Activity',
            'outputs' => 'Output',
            'outcomes' => 'Outcome',
            'kpis' => 'KPI',
        ];
        $section = $request->string('section', 'overview')->value();
        abort_unless($section === 'overview' || $section === 'evidence' || array_key_exists($section, $sectionCategories), 404);

        $period = $section === 'overview'
            ? $this->latestProjectPeriod($request, $project, $scope)
            : $periods->fromRequest($request);
        $centreId = $request->integer('centre') ?: null;
        if ($centreId) {
            abort_unless($scope->centres($request->user())->whereKey($centreId)->whereHas('projects', fn (Builder $query) => $query->whereKey($project->id))->exists(), 403);
        }

        $project->load(['programme', 'lead', 'centres' => fn ($query) => $query->whereIn('centres.id', $scope->centres($request->user())->select('centres.id'))]);
        if ($section === 'evidence') {
            Gate::authorize('submit');
        }
        $selectedCategory = $sectionCategories[$section] ?? null;
        $visibleCentreIds = $project->centres->modelKeys();
        $availableCategories = $project->indicators()
            ->where('active', true)
            ->whereHas('assignments', fn (Builder $query): Builder => $query->whereIn('centre_id', $visibleCentreIds))
            ->distinct()
            ->pluck('category');
        $indicatorQuery = $project->indicators()
            ->where('active', true)
            ->whereHas('assignments', fn (Builder $query): Builder => $query->whereIn('centre_id', $visibleCentreIds))
            ->withCount(['assignments' => fn (Builder $query): Builder => $query->whereIn('centre_id', $visibleCentreIds)]);
        if ($selectedCategory) {
            $indicatorQuery->where('category', $selectedCategory);
        }
        if ($centreId) {
            $indicatorQuery->whereHas('assignments', fn (Builder $query): Builder => $query->where('centre_id', $centreId));
        }
        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->trim()->value().'%';
            $indicatorQuery->where(fn ($query) => $query->where('name', 'like', $search)->orWhere('code', 'like', $search));
        }

        $evidence = null;
        if ($section === 'evidence') {
            $scopedEntryIds = $scope->entries($request->user())->select('indicator_entries.id');
            $evidence = EvidenceFile::query()
                ->whereHas('revision.entry', function (Builder $query) use ($scopedEntryIds, $project, $centreId, $period): void {
                    $query->whereIn('indicator_entries.id', $scopedEntryIds)
                        ->whereBetween('period', [$period['data_start'], $period['data_end']])
                        ->whereHas('assignment.indicator', fn (Builder $indicatorQuery) => $indicatorQuery->where('project_id', $project->id));
                    if ($centreId) {
                        $query->whereHas('assignment', fn (Builder $assignmentQuery) => $assignmentQuery->where('centre_id', $centreId));
                    }
                })
                ->with(['user', 'revision.entry.assignment.indicator', 'revision.entry.assignment.centre'])
                ->latest()
                ->paginate(24)
                ->withQueryString();
        }

        $rows = collect();
        $targetSummaries = collect();
        $indicators = $indicatorQuery->orderBy('category')->orderBy('name')->get();
        if ($selectedCategory) {
            $rows = $centreId
                ? $reports->report($request->user(), $period['data_start'], $period['data_end'], $project->id, $centreId, null, $selectedCategory)
                : $reports->consolidatedReport($request->user(), $period['data_start'], $period['data_end'], $project->id, null, $selectedCategory);

            $assignments = $scope->assignments($request->user())
                ->whereIn('indicator_definition_id', $indicators->modelKeys())
                ->when($centreId, fn (Builder $query): Builder => $query->where('centre_id', $centreId))
                ->get();
            $assignmentsById = $assignments->keyBy('id');
            $targetsByIndicator = IndicatorTarget::query()
                ->whereIn('indicator_assignment_id', $assignments->modelKeys())
                ->where('active', true)
                ->whereDate('period', '<=', $period['data_end'])
                ->whereDate('period_end', '>=', $period['data_start'])
                ->get()
                ->groupBy(fn (IndicatorTarget $target): int => $assignmentsById->get($target->indicator_assignment_id)->indicator_definition_id);
            $targetSummaries = $indicators->mapWithKeys(fn (IndicatorDefinition $indicator): array => [
                $indicator->id => $reports->summarizeTargets($targetsByIndicator->get($indicator->id, collect()), $indicator),
            ]);
        }

        return view('projects.show', [
            'project' => $project,
            'period' => $period,
            'section' => $section,
            'sectionCategories' => $sectionCategories,
            'availableCategories' => $availableCategories,
            'firstIndicatorSection' => collect($sectionCategories)
                ->search(fn (string $category): bool => $availableCategories->contains($category)) ?: null,
            'indicators' => $indicators,
            'rows' => $rows,
            'targetSummaries' => $targetSummaries,
            'evidence' => $evidence,
            'completeness' => $completeness->summary($request->user(), $period['data_start'], $period['data_end'], $project->id, $centreId),
        ]);
    }

    /** @return array{type: string, start: CarbonImmutable, end: CarbonImmutable, data_start: CarbonImmutable, data_end: CarbonImmutable, warning: null} */
    private function latestProjectPeriod(Request $request, Project $project, ScopeService $scope): array
    {
        $latestEntryPeriod = $scope->entries($request->user())
            ->whereHas('assignment.indicator', fn (Builder $query): Builder => $query->where('project_id', $project->id))
            ->max('period');
        $month = CarbonImmutable::parse($latestEntryPeriod ?: now())->startOfMonth();

        return [
            'type' => 'monthly',
            'start' => $month,
            'end' => $month->endOfMonth(),
            'data_start' => $month,
            'data_end' => $month->endOfMonth(),
            'warning' => null,
        ];
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
