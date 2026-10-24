<?php

namespace App\Services;

use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Models\User;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CompletenessService
{
    public function __construct(
        private readonly ScopeService $scope,
        private readonly IndicatorReportingPeriodService $periods,
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
    ): array {
        $assignments = $this->scope->assignments($user)
            ->where('required', true)
            ->whereHas('indicator', function ($query) use ($end, $projectId, $frequency): void {
                $query->where('active', true)->whereDate('effective_date', '<=', $end);
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
                if ($frequency) {
                    $query->where('frequency', $frequency);
                }
            })
            ->when($centreId, fn ($query) => $query->where('centre_id', $centreId))
            ->with(['indicator.project', 'centre'])
            ->get();

        $assignmentsById = $assignments->keyBy('id');
        $entries = $this->scope->entries($user)
            ->whereBetween('period', [$start->startOfMonth(), $end->endOfMonth()])
            ->whereIn('indicator_assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy(function (IndicatorEntry $entry) use ($assignmentsById): string {
                $frequency = $assignmentsById->get($entry->indicator_assignment_id)->indicator->frequency;
                $period = $this->periods->start($entry->period, $frequency);

                return $entry->indicator_assignment_id.'-'.$period->format('Y-m-d');
            });

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
            'approved' => $reported->where('entry.status', WorkflowStatus::FinalApproved)->count(),
            'pending' => $reported->whereIn('entry.status', WorkflowStatus::pending())->count(),
            'draft' => $reported->where('entry.status', WorkflowStatus::Draft)->count(),
            'returned' => $reported->where('entry.status', WorkflowStatus::ReturnedForCorrection)->count(),
            'rejected' => $reported->where('entry.status', WorkflowStatus::Rejected)->count(),
            'missing' => max(0, $expected - $reported->count()),
            'rate' => $expected > 0 ? round($reported->count() / $expected * 100, 1) : 100.0,
            'missing_items' => $obligations->whereNull('entry')->take(100)->values(),
        ];
    }

    /** @return Collection<int, CarbonImmutable> */
    private function duePeriods(IndicatorAssignment $assignment, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $periods = collect();
        $frequency = $assignment->indicator->frequency;
        $cursor = $this->periods->start($start, $frequency);
        $last = $end->startOfMonth();
        $effective = CarbonImmutable::parse($assignment->indicator->effective_date)->startOfMonth();

        while ($cursor->lte($last)) {
            [, $periodEnd] = $this->periods->boundaries($cursor, $frequency);
            if ($cursor->gte($start->startOfMonth()) && $periodEnd->gte($effective)) {
                $periods->push($cursor);
            }
            $cursor = $cursor->addMonths($this->periods->months($frequency));
        }

        return $periods;
    }
}
