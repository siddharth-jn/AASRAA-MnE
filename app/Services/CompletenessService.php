<?php

namespace App\Services;

use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\User;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CompletenessService
{
    public function __construct(
        private readonly ScopeService $scope,
        private readonly DonorService $donors,
    ) {}

    /**
     * @return array{expected: int, reported: int, approved: int, pending: int, draft: int, returned: int, rejected: int, missing: int, rate: float, missing_items: Collection<int, array<string, mixed>>}
     */
    public function summary(
        User $user,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $projectId = null,
        ?int $centreId = null,
        ?string $frequency = null,
        ?string $donor = null,
        ?string $indicatorSearch = null,
    ): array {
        $assignments = $this->scope->assignments($user)
            ->where('required', true)
            ->whereHas('indicator', function (Builder $query) use ($end, $projectId, $indicatorSearch): void {
                $query->where('active', true)->whereDate('effective_date', '<=', $end);
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
                if ($indicatorSearch) {
                    $query->where(function (Builder $searchQuery) use ($indicatorSearch): void {
                        $searchQuery->where('name', 'like', '%'.$indicatorSearch.'%')
                            ->orWhere('code', 'like', '%'.$indicatorSearch.'%');
                    });
                }
            })
            ->when($centreId, fn (Builder $query): Builder => $query->where('centre_id', $centreId))
            ->when($donor, fn (Builder $query, string $donorName): Builder => $this->donors->applyCentreFilter($query, $donorName, $start, $end))
            ->with(['indicator.project', 'centre'])
            ->get();

        $targets = IndicatorTarget::query()
            ->whereIn('indicator_assignment_id', $assignments->pluck('id'))
            ->where('period_type', 'monthly')
            ->where('active', true)
            ->whereBetween('period', [$start->startOfMonth(), $end->endOfMonth()])
            ->orderBy('period')
            ->get();
        $entries = $this->scope->entries($user)
            ->whereBetween('period', [$start->startOfMonth(), $end->endOfMonth()])
            ->whereIn('indicator_assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy(fn (IndicatorEntry $entry): string => $entry->indicator_assignment_id.'-'.$entry->period->startOfMonth()->format('Y-m-d'));

        $assignmentsById = $assignments->keyBy('id');
        $obligations = $targets->map(function (IndicatorTarget $target) use ($assignmentsById, $entries): array {
            $period = CarbonImmutable::parse($target->period)->startOfMonth();

            return [
                'assignment' => $assignmentsById->get($target->indicator_assignment_id),
                'period' => $period,
                'target' => $target,
                'entry' => $entries->get($target->indicator_assignment_id.'-'.$period->format('Y-m-d')),
            ];
        });

        $reported = $obligations->filter(fn (array $item): bool => $item['entry'] !== null);
        $expected = $obligations->count();

        return [
            'expected' => $expected,
            'reported' => $reported->count(),
            'approved' => $reported->filter(fn (array $item): bool => $item['entry']->approved_revision_id !== null)->count(),
            'pending' => $reported->whereIn('entry.status', WorkflowStatus::pending())->count(),
            'draft' => $reported->where('entry.status', WorkflowStatus::Draft)->count(),
            'returned' => $reported->whereIn('entry.status', [WorkflowStatus::ReturnedForCorrection, WorkflowStatus::ReEntryRequired])->count(),
            'rejected' => $reported->where('entry.status', WorkflowStatus::Rejected)->count(),
            'missing' => max(0, $expected - $reported->count()),
            'rate' => $expected > 0 ? round($reported->count() / $expected * 100, 1) : 100.0,
            'missing_items' => $obligations->whereNull('entry')->take(100)->values(),
        ];
    }
}
