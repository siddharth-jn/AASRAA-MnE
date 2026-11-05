<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Services\CompletenessService;
use App\Services\ReportingPeriodService;
use App\Services\ScopeService;
use App\Services\TargetService;
use App\Support\WorkflowStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function dashboard(Request $request, ScopeService $scope, CompletenessService $completeness): View
    {
        $targetBackedEntries = $scope->entries($request->user())->targetBacked();
        $monthStart = now()->toImmutable()->startOfMonth();
        $monthEnd = now()->toImmutable()->endOfMonth();
        $responsibilities = Gate::allows('submit')
            ? $completeness->summary($request->user(), $monthStart, $monthEnd)
            : null;
        $approvalStatuses = match ($request->user()->role) {
            'SENIOR PROGRAM LEAD' => [WorkflowStatus::PendingSeniorProgrammeLead],
            'M&E HEAD' => [WorkflowStatus::PendingMneHead],
            default => WorkflowStatus::pending(),
        };

        return view('dashboard', [
            'stats' => [
                'projects' => $scope->projects($request->user())->where('active', true)->count(),
                'centres' => $scope->centres($request->user())->where('active', true)->count(),
                'pending' => (clone $targetBackedEntries)->whereIn('status', $approvalStatuses)->count(),
                'approved' => (clone $targetBackedEntries)->whereNotNull('approved_revision_id')->count(),
            ],
            'responsibilities' => $responsibilities,
            'recentEntries' => (clone $targetBackedEntries)->with(['assignment.indicator.project', 'assignment.centre', 'currentRevision'])->latest('updated_at')->limit(8)->get(),
            'recentApproved' => (clone $targetBackedEntries)->whereNotNull('approved_revision_id')->with(['assignment.indicator.project', 'assignment.centre', 'approvedRevision'])->latest('updated_at')->limit(5)->get(),
        ]);
    }

    public function projects(Request $request, ScopeService $scope): View
    {
        return view('projects.index', ['projects' => $scope->projects($request->user())->withCount([
            'centres',
            'indicators as input_indicators_count' => fn (Builder $query): Builder => $query->where('active', true)->where('category', 'Input'),
            'indicators as activity_indicators_count' => fn (Builder $query): Builder => $query->where('active', true)->where('category', 'Activity'),
            'indicators as output_indicators_count' => fn (Builder $query): Builder => $query->where('active', true)->where('category', 'Output'),
            'indicators as outcome_indicators_count' => fn (Builder $query): Builder => $query->where('active', true)->where('category', 'Outcome'),
        ])->paginate(12)]);
    }

    public function project(Request $request, Project $project, ScopeService $scope): View
    {
        abort_unless($scope->projects($request->user())->whereKey($project)->exists(), 403);
        $categoryLabels = [
            'Input' => 'Inputs',
            'Activity' => 'Activities',
            'Output' => 'Outputs',
            'Outcome' => 'Outcomes',
        ];
        $project->load([
            'lead',
            'centres' => fn ($query) => $query
                ->whereIn('centres.id', $scope->centres($request->user())->select('centres.id'))
                ->with('lead'),
        ]);
        $visibleCentreIds = $project->centres->modelKeys();
        $indicators = $project->indicators()
            ->where('active', true)
            ->whereIn('category', config('impact.categories'))
            ->whereHas('assignments', fn (Builder $query): Builder => $query->where(function (Builder $scopeQuery) use ($visibleCentreIds): void {
                $scopeQuery->whereNull('centre_id')->orWhereIn('centre_id', $visibleCentreIds);
            }))
            ->withCount(['assignments as assigned_projects_count' => fn (Builder $query): Builder => $query->whereNotNull('centre_id')->whereIn('centre_id', $visibleCentreIds)])
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        return view('projects.show', [
            'project' => $project,
            'indicators' => $indicators,
            'categoryLabels' => $categoryLabels,
        ]);
    }

    public function centres(Request $request, ScopeService $scope): View
    {
        $request->validate([
            'programme' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $visibleCentreIds = $scope->centres($request->user())->select('centres.id');
        $programmes = $scope->projects($request->user())
            ->whereHas('centres', fn (Builder $query): Builder => $query->whereIn('centres.id', $visibleCentreIds))
            ->orderBy('name')
            ->get();
        $programmeId = $request->integer('programme') ?: null;
        $search = trim((string) $request->query('q', ''));

        if ($programmeId) {
            abort_unless($programmes->contains('id', $programmeId), 403);
        }

        $projectsQuery = $scope->centres($request->user())
            ->with('lead')
            ->when($programmeId, fn (Builder $query): Builder => $query->whereHas(
                'projects',
                fn (Builder $programmeQuery): Builder => $programmeQuery->whereKey($programmeId),
            ));
        $projectOptions = (clone $projectsQuery)
            ->orderBy('name')
            ->get(['centres.id', 'centres.name', 'centres.code', 'centres.source_project_id']);
        $programmeIds = $programmes->modelKeys();
        $projects = $projectsQuery
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(function (Builder $searchQuery) use ($term): void {
                    $searchQuery->where('centres.name', 'like', $term)
                        ->orWhere('centres.code', 'like', $term)
                        ->orWhere('centres.source_project_id', 'like', $term);
                });
            })
            ->with(['projects' => fn ($query) => $query
                ->whereIn('projects.id', $programmeIds)
                ->orderBy('projects.name')])
            ->orderBy('centres.name')
            ->paginate(15)
            ->withQueryString();

        return view('centres.index', [
            'centres' => $projects,
            'programmes' => $programmes,
            'projectOptions' => $projectOptions,
            'programmeId' => $programmeId,
            'search' => $search,
        ]);
    }

    public function centre(Request $request, Centre $centre, ScopeService $scope, ReportingPeriodService $periods, TargetService $targets): View
    {
        abort_unless($scope->centres($request->user())->whereKey($centre)->exists(), 403);
        $request->validate([
            'anchor' => ['nullable', 'date_format:Y-m'],
            'project' => ['nullable', 'integer'],
        ]);
        $request->merge([
            'period_type' => 'monthly',
            'anchor' => $request->input('anchor', now()->format('Y-m')),
        ]);
        $period = $periods->fromRequest($request);
        $projectId = $request->integer('project') ?: null;
        if ($projectId) {
            abort_unless($scope->projects($request->user())->whereKey($projectId)->whereHas('centres', fn (Builder $query) => $query->whereKey($centre->id))->exists(), 403);
        }

        $centre->load(['projects' => fn ($query) => $query->whereIn('projects.id', $scope->projects($request->user())->select('projects.id')), 'users', 'lead']);
        $assignments = $scope->assignments($request->user())
            ->where('centre_id', $centre->id)
            ->whereHas('indicator', function (Builder $query) use ($projectId): void {
                $query->where('active', true);
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
            })
            ->with('indicator.project')
            ->get()
            ->sortBy([
                ['indicator.category', 'asc'],
                ['indicator.name', 'asc'],
            ])
            ->values();
        $assignmentIds = $assignments->pluck('id');
        $periodStart = $period['start']->startOfMonth();
        $periodEntries = $scope->entries($request->user())
            ->whereIn('indicator_assignment_id', $assignmentIds)
            ->whereDate('period', $periodStart)
            ->with(['currentRevision', 'approvedRevision'])
            ->get()
            ->keyBy('indicator_assignment_id');
        $periodTargets = IndicatorTarget::query()
            ->whereIn('indicator_assignment_id', $assignmentIds)
            ->where('period_type', 'monthly')
            ->where('active', true)
            ->whereDate('period', $periodStart)
            ->get()
            ->keyBy('indicator_assignment_id');
        $canSubmit = Gate::allows('submit');
        $indicatorRows = $assignments->map(function (IndicatorAssignment $assignment) use ($canSubmit, $periodEntries, $periodTargets, $targets): array {
            $target = $periodTargets->get($assignment->id);
            $entry = $target ? $periodEntries->get($assignment->id) : null;

            return [
                'assignment' => $assignment,
                'target' => $target,
                'target_display' => $targets->display($target),
                'entry' => $entry,
                'revision' => $entry ? ($canSubmit ? $entry->currentRevision : $entry->approvedRevision) : null,
            ];
        });
        $expectedIndicators = $indicatorRows->whereNotNull('target')->count();
        $filledIndicators = $indicatorRows
            ->filter(fn (array $row): bool => $row['target'] !== null && $row['entry'] !== null)
            ->count();

        return view('centres.show', [
            'centre' => $centre,
            'indicatorRows' => $indicatorRows,
            'period' => $period,
            'selectedProjectId' => $projectId,
            'canSubmit' => $canSubmit,
            'completion' => [
                'expected' => $expectedIndicators,
                'filled' => $filledIndicators,
            ],
        ]);
    }
}
