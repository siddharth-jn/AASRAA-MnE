<?php

namespace App\Http\Controllers;

use App\Services\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExecutiveDashboardController extends Controller
{
    public function __invoke(Request $request, ReportingService $reports): View
    {
        Gate::authorize('executive-dashboard');

        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'performance' => ['nullable', Rule::in(['exceptions', 'needs_attention', 'overachiever'])],
        ]);

        $month = CarbonImmutable::createFromFormat('Y-m', $request->string('month', now()->format('Y-m'))->value())->startOfMonth();
        $selectedPerformance = $request->string('performance', 'exceptions')->value();
        $monthlyRows = $reports->report($request->user(), $month, $month->endOfMonth())
            ->filter(fn (array $row): bool => $row['approved_months'] > 0)
            ->values();
        $exceptions = $monthlyRows
            ->whereIn('performance_status', ['Needs attention', 'Overachiever'])
            ->values();
        $needsAttention = $exceptions->where('performance_status', 'Needs attention')->values();
        $overachievers = $exceptions->where('performance_status', 'Overachiever')->values();
        $rows = match ($selectedPerformance) {
            'needs_attention' => $needsAttention,
            'overachiever' => $overachievers,
            default => $exceptions,
        };

        return view('executive.dashboard', [
            'month' => $month,
            'selectedPerformance' => $selectedPerformance,
            'rows' => $rows,
            'stats' => [
                'approved_results' => $monthlyRows->count(),
                'needs_attention' => $needsAttention->count(),
                'overachievers' => $overachievers->count(),
                'projects_with_exceptions' => $exceptions
                    ->map(fn (array $row): string => $row['centre']?->getKey() ? 'centre-'.$row['centre']->getKey() : 'programme-'.$row['project']->getKey())
                    ->unique()
                    ->count(),
            ],
        ]);
    }
}
