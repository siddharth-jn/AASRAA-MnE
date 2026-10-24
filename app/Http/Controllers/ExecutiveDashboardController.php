<?php

namespace App\Http\Controllers;

use App\Services\ScopeService;
use App\Support\WorkflowStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExecutiveDashboardController extends Controller
{
    public function __invoke(Request $request, ScopeService $scope): View
    {
        Gate::authorize('executive-dashboard');

        $approved = $scope->entries($request->user())
            ->where('status', WorkflowStatus::FinalApproved)
            ->whereNotNull('approved_revision_id');

        return view('executive.dashboard', [
            'stats' => [
                'approved_this_month' => (clone $approved)->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'variance_exceptions' => (clone $approved)->whereHas('approvedRevision', fn ($query) => $query->where('variance_flagged', true))->count(),
                'programmes_reporting' => (clone $approved)->whereHas('assignment.indicator')->distinct()->count('indicator_assignment_id'),
            ],
            'recentApproved' => $approved
                ->with(['assignment.indicator.project', 'assignment.centre', 'approvedRevision.user'])
                ->latest('updated_at')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }
}
