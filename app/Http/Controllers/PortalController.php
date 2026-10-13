<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\Project;
use App\Services\ScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function dashboard(Request $request, ScopeService $scope): View
    {
        $period = $request->date('period') ?? now()->setDate(2026, 9, 1)->startOfMonth();
        $entries = $scope->entries($request->user())->whereDate('period', $period)->get();
        $expected = $scope->assignments($request->user())->whereHas('indicator', fn (Builder $query) => $query->where('active', true))->count();

        return view('dashboard', [
            'period' => $period,
            'stats' => ['projects' => $scope->projects($request->user())->where('active', true)->count(), 'centres' => $scope->centres($request->user())->where('active', true)->count(), 'completion' => $expected ? round($entries->count() / $expected * 100) : 0, 'pending' => $entries->where('status', 'SUBMITTED')->count()],
            'statuses' => $entries->countBy('status'),
            'recentEntries' => $scope->entries($request->user())->with(['assignment.indicator.project', 'assignment.centre', 'currentRevision'])->latest('updated_at')->limit(8)->get(),
        ]);
    }

    public function projects(Request $request, ScopeService $scope): View
    {
        return view('projects.index', ['projects' => $scope->projects($request->user())->withCount(['centres', 'indicators'])->with('programme')->paginate(12)]);
    }

    public function project(Request $request, Project $project, ScopeService $scope): View
    {
        abort_unless($scope->projects($request->user())->whereKey($project)->exists(), 403);
        $project->load(['programme', 'centres', 'indicators.assignments']);

        return view('projects.show', compact('project'));
    }

    public function centres(Request $request, ScopeService $scope): View
    {
        return view('centres.index', ['centres' => $scope->centres($request->user())->withCount('projects')->paginate(15)]);
    }

    public function centre(Request $request, Centre $centre, ScopeService $scope): View
    {
        abort_unless($scope->centres($request->user())->whereKey($centre)->exists(), 403);
        $centre->load(['projects', 'users']);
        $entries = $scope->entries($request->user())->whereHas('assignment', fn (Builder $query) => $query->where('centre_id', $centre->id))->with(['assignment.indicator', 'approvedRevision'])->latest('period')->paginate(25);

        return view('centres.show', compact('centre', 'entries'));
    }
}
