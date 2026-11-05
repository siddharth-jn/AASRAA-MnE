<?php

namespace App\Http\Controllers;

use App\Models\ApprovalAction;
use App\Models\Centre;
use App\Models\IndicatorEntry;
use App\Services\CompletenessService;
use App\Services\ScopeService;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DataQualityController extends Controller
{
    public function index(Request $request, ScopeService $scope, CompletenessService $completeness): View
    {
        Gate::authorize('quality');
        $period = CarbonImmutable::parse($request->input('period', CarbonImmutable::now()->format('Y-m')))->startOfMonth();
        $summary = $completeness->summary($request->user(), $period, $period->endOfMonth());
        $entries = $scope->entries($request->user())
            ->whereDate('period', $period)
            ->with(['assignment.indicator', 'currentRevision.evidence'])
            ->get();
        $requiredEvidence = $entries->filter(fn (IndicatorEntry $entry): bool => $entry->assignment->indicator->evidence_required && in_array($entry->status, [...WorkflowStatus::pending(), WorkflowStatus::FinalApproved], true));
        $evidenceCompliant = $requiredEvidence->filter(fn (IndicatorEntry $entry): bool => $entry->currentRevision?->evidence->isNotEmpty())->count();
        $actions = ApprovalAction::query()
            ->whereHas('revision.entry', fn ($query) => $query->whereIn('indicator_entries.id', $entries->pluck('id')))
            ->with('revision')
            ->get();
        $turnaround = $actions->filter(fn (ApprovalAction $action): bool => $action->revision?->submitted_at !== null)
            ->map(fn (ApprovalAction $action): int => $action->revision->submitted_at->diffInMinutes($action->created_at));
        $deadline = $period->addMonth()->setDay((int) config('impact.reporting_deadline_day', 5))->endOfDay();
        $submitted = $entries->filter(fn (IndicatorEntry $entry): bool => $entry->currentRevision?->submitted_at !== null);
        $onTime = $submitted->filter(fn (IndicatorEntry $entry): bool => $entry->currentRevision->submitted_at->lte($deadline))->count();

        $centreBreakdown = $scope->centres($request->user())->where('active', true)->orderBy('name')->get()
            ->map(fn (Centre $centre): array => ['centre' => $centre, 'summary' => $completeness->summary($request->user(), $period, $period->endOfMonth(), null, $centre->id)]);

        return view('data-quality.index', [
            'period' => $period,
            'summary' => $summary,
            'metrics' => [
                'evidence_compliance' => $requiredEvidence->count() ? round($evidenceCompliant / $requiredEvidence->count() * 100, 1) : 100,
                'approval_turnaround' => $turnaround->isNotEmpty() ? round($turnaround->avg() / 60, 1) : null,
                'on_time' => $submitted->count() ? round($onTime / $submitted->count() * 100, 1) : 100,
                'returned' => $actions->count() ? round($actions->where('action', 'RETURNED FOR CORRECTION')->count() / $actions->count() * 100, 1) : 0,
                'unapproved' => $entries->whereNotIn('status', [WorkflowStatus::FinalApproved])->count(),
            ],
            'centres' => $centreBreakdown,
        ]);
    }
}
