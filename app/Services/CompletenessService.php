<?php

namespace App\Services;

use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CompletenessService
{
    public function __construct(private readonly ScopeService $scope) {}

    /**
     * @return array{expected: int, reported: int, approved: int, pending: int, draft: int, returned: int, rejected: int, missing: int, rate: float, missing_items: Collection<int, array<string, mixed>>}
     */
    public function summary(User $user, CarbonImmutable $start, CarbonImmutable $end, ?int $projectId = null, ?int $centreId = null): array
    {
        $assignments = $this->scope->assignments($user)
            ->where('required', true)
            ->whereHas('indicator', function ($query) use ($end, $projectId): void {
                $query->where('active', true)->whereDate('effective_date', '<=', $end);
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
            })
            ->when($centreId, fn ($query) => $query->where('centre_id', $centreId))
            ->with(['indicator.project', 'centre'])
            ->get();

        $entries = $this->scope->entries($user)
            ->whereBetween('period', [$start->startOfMonth(), $end->endOfMonth()])
            ->whereIn('indicator_assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy(fn (IndicatorEntry $entry): string => $entry->indicator_assignment_id.'-'.$entry->period->format('Y-m-d'));

        $obligations = collect();
        foreach ($assignments as $assignment) {
            foreach ($this->duePeriods($assignment, $start, $end) as $period) {
                $entry = $entries->get($assignment->id.'-'.$period->format('Y-m-d'));
                $obligations->push(['assignment' => $assignment, 'period' => $period, 'entry' => $entry]);
            }
        }

        $reported = $obligations->filter(fn (array $item): bool => $item['entry'] !== null);
        $expected = $obligations->count();

        return [
            'expected' => $expected,
            'reported' => $reported->count(),
            'approved' => $reported->where('entry.status', 'APPROVED')->count(),
            'pending' => $reported->where('entry.status', 'SUBMITTED')->count(),
            'draft' => $reported->where('entry.status', 'DRAFT')->count(),
            'returned' => $reported->where('entry.status', 'RETURNED FOR CORRECTION')->count(),
            'rejected' => $reported->where('entry.status', 'REJECTED')->count(),
            'missing' => max(0, $expected - $reported->count()),
            'rate' => $expected > 0 ? round($reported->count() / $expected * 100, 1) : 100.0,
            'missing_items' => $obligations->whereNull('entry')->take(100)->values(),
        ];
    }

    /** @return Collection<int, CarbonImmutable> */
    private function duePeriods(IndicatorAssignment $assignment, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $periods = collect();
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();
        $effective = CarbonImmutable::parse($assignment->indicator->effective_date)->startOfMonth();

        while ($cursor->lte($last)) {
            if ($cursor->gte($effective) && $this->isDue($cursor, $assignment->indicator->frequency)) {
                $periods->push($cursor);
            }
            $cursor = $cursor->addMonth();
        }

        return $periods;
    }

    private function isDue(CarbonImmutable $period, string $frequency): bool
    {
        $offset = ($period->month - config('impact.financial_year_start_month', 4) + 12) % 12;

        return match ($frequency) {
            'quarterly' => $offset % 3 === 0,
            'half_yearly' => $offset % 6 === 0,
            'annual' => $offset === 0,
            default => true,
        };
    }
}
