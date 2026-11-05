<?php

namespace App\Services;

use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportingService
{
    public function __construct(
        private readonly ScopeService $scope,
        private readonly AggregationService $aggregation,
        private readonly IndicatorService $indicators,
        private readonly DonorService $donors,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function report(
        User $user,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $projectId = null,
        ?int $centreId = null,
        ?int $indicatorId = null,
        ?string $category = null,
        ?string $donor = null,
    ): Collection {
        if ($end->lt($start)) {
            return collect();
        }

        $assignmentIds = $this->scope->assignments($user)
            ->whereHas('indicator', function (Builder $query) use ($projectId, $indicatorId, $category): void {
                $query->where('active', true)->where('frequency', 'monthly');
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
                if ($indicatorId) {
                    $query->whereKey($indicatorId);
                }
                if ($category) {
                    $query->where('category', $category);
                }
            })
            ->when($centreId, fn (Builder $query): Builder => $query->where('centre_id', $centreId))
            ->when($donor, fn (Builder $query): Builder => $this->donors->applyCentreFilter($query, $donor, $start, $end))
            ->select('indicator_assignments.id');

        $targets = IndicatorTarget::query()
            ->whereIn('indicator_assignment_id', $assignmentIds)
            ->where('period_type', 'monthly')
            ->where('active', true)
            ->whereBetween('period', [$start->startOfMonth(), $end->endOfMonth()])
            ->with(['assignment.indicator.project', 'assignment.centre.donorAssignments.donor'])
            ->orderBy('period')
            ->get();

        $entries = IndicatorEntry::query()
            ->whereIn('indicator_assignment_id', $targets->pluck('indicator_assignment_id')->unique())
            ->whereBetween('period', [$start->startOfMonth(), $end->endOfMonth()])
            ->with(['approvedRevision', 'currentRevision'])
            ->get()
            ->keyBy(fn (IndicatorEntry $entry): string => $entry->indicator_assignment_id.'-'.$entry->period->format('Y-m-d'));

        return $targets
            ->groupBy('indicator_assignment_id')
            ->map(function (Collection $assignmentTargets) use ($entries): array {
                /** @var IndicatorTarget $firstTarget */
                $firstTarget = $assignmentTargets->first();
                $assignment = $firstTarget->assignment;
                $indicator = $assignment->indicator;
                $approvedEntries = collect();
                $records = [];

                foreach ($assignmentTargets as $target) {
                    $entry = $entries->get($assignment->id.'-'.$target->period->format('Y-m-d'));
                    if (! $entry?->approvedRevision) {
                        continue;
                    }

                    $revision = $entry->approvedRevision;
                    $approvedEntries->push($entry);
                    $records[] = [
                        'value' => $revision->numeric_value ?? ($revision->boolean_value === null ? null : (int) $revision->boolean_value),
                        'numerator' => $revision->numerator,
                        'denominator' => $revision->denominator,
                        'weight' => $revision->weight,
                        'status' => $revision->text_value,
                    ];
                }

                $achievement = $this->aggregation->aggregate($records, $indicator->time_aggregation);
                $targetData = $this->aggregateTargets($assignmentTargets, $indicator->time_aggregation);
                $performance = $this->performance($achievement, $targetData, $indicator->direction);
                $expectedMonths = $assignmentTargets->count();
                $approvedMonths = $approvedEntries->count();
                $donorNames = $this->donors->namesForPeriod($assignment->centre, $assignmentTargets->min('period'), $assignmentTargets->max('period_end'));

                return [
                    'indicator' => $indicator,
                    'project' => $indicator->project,
                    'centre' => $assignment->centre,
                    'donor_names' => $donorNames,
                    'donor_display' => $donorNames->join(', ') ?: 'Not specified',
                    'achievement' => $achievement,
                    'target' => $targetData['value'],
                    'target_data' => $targetData,
                    'target_display' => $targetData['display'],
                    'variance' => $performance['variance'],
                    'achievement_percentage' => $performance['percentage'],
                    'performance_status' => $performance['status'],
                    'variance_explanations' => $approvedEntries->pluck('approvedRevision.variance_explanation')->filter()->unique()->values(),
                    'months' => $approvedMonths,
                    'expected_months' => $expectedMonths,
                    'approved_months' => $approvedMonths,
                    'status' => $approvedMonths === $expectedMonths ? 'FINAL APPROVED' : "INCOMPLETE {$approvedMonths}/{$expectedMonths}",
                    'numerator' => collect($records)->sum('numerator'),
                    'denominator' => collect($records)->sum('denominator'),
                    'weight' => collect($records)->sum('weight'),
                    'scope' => $indicator->reporting_scope ?? 'project',
                    'trend' => $assignmentTargets->map(function (IndicatorTarget $target) use ($entries, $assignment): array {
                        $entry = $entries->get($assignment->id.'-'.$target->period->format('Y-m-d'));

                        return [
                            'period' => $target->period->format('Y-m-d'),
                            'value' => $entry?->approvedRevision?->numeric_value,
                        ];
                    })->values(),
                ];
            })
            ->sortBy(fn (array $row): string => implode('|', [
                $row['project']->name,
                $row['centre']?->name ?? ' Programme level',
                str_pad((string) $this->categoryOrder($row['indicator']->category), 2, '0', STR_PAD_LEFT),
                $row['indicator']->name,
            ]))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function consolidatedReport(
        User $user,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $projectId = null,
        ?int $indicatorId = null,
        ?string $category = null,
        ?string $donor = null,
    ): Collection {
        return $this->report($user, $start, $end, $projectId, null, $indicatorId, $category, $donor)
            ->groupBy(fn (array $row): int => $row['indicator']->id)
            ->map(function (Collection $rows) use ($donor): array {
                $first = $rows->first();
                $indicator = $first['indicator'];
                if (($indicator->reporting_scope ?? 'project') === 'programme') {
                    return array_replace($first, [
                        'centre' => null,
                        'centre_count' => null,
                        'donor_display' => 'Programme level',
                        'across_project_method' => 'NON-AGGREGATABLE',
                    ]);
                }

                $method = array_key_exists($indicator->centre_aggregation, $this->indicators->acrossProjectOptions($indicator->data_type, $indicator->calculation_method))
                    ? $indicator->centre_aggregation
                    : 'NON-AGGREGATABLE';
                $records = $rows->map(fn (array $row): array => [
                    'value' => is_numeric($row['achievement']) ? (float) $row['achievement'] : null,
                    'numerator' => $row['numerator'],
                    'denominator' => $row['denominator'],
                    'weight' => $row['weight'],
                    'status' => is_string($row['achievement']) ? $row['achievement'] : null,
                ])->all();
                $achievement = $method === 'STATUS'
                    ? $rows->reduce(function (array $counts, array $row): array {
                        foreach ((array) $row['achievement'] as $status => $count) {
                            $counts[$status] = ($counts[$status] ?? 0) + $count;
                        }

                        return $counts;
                    }, [])
                    : $this->aggregation->aggregate($records, $method);
                $targetData = $this->consolidateTargets($rows, $method);
                $performance = $this->performance($achievement, $targetData, $indicator->direction);
                $expectedMonths = $rows->sum('expected_months');
                $approvedMonths = $rows->sum('approved_months');

                return array_replace($first, [
                    'centre' => null,
                    'centre_count' => $rows->count(),
                    'donor_display' => $donor ?? match ($rows->flatMap(fn (array $row): Collection => $row['donor_names'])->filter()->unique()->count()) {
                        0 => 'Not specified',
                        1 => $rows->flatMap(fn (array $row): Collection => $row['donor_names'])->filter()->first(),
                        default => 'Multiple donors',
                    },
                    'across_project_method' => $method,
                    'achievement' => $achievement,
                    'target' => $targetData['value'],
                    'target_data' => $targetData,
                    'target_display' => $targetData['display'],
                    'variance' => $performance['variance'],
                    'achievement_percentage' => $performance['percentage'],
                    'performance_status' => $performance['status'],
                    'months' => $approvedMonths,
                    'expected_months' => $expectedMonths,
                    'approved_months' => $approvedMonths,
                    'status' => $approvedMonths === $expectedMonths ? 'FINAL APPROVED' : "INCOMPLETE {$approvedMonths}/{$expectedMonths}",
                    'numerator' => $rows->sum('numerator'),
                    'denominator' => $rows->sum('denominator'),
                    'weight' => $rows->sum('weight'),
                    'variance_explanations' => $rows->flatMap(fn (array $row): Collection => $row['variance_explanations'])->unique()->values(),
                    'trend' => collect(),
                ]);
            })
            ->sortBy(fn (array $row): string => implode('|', [
                $row['project']->name,
                str_pad((string) $this->categoryOrder($row['indicator']->category), 2, '0', STR_PAD_LEFT),
                $row['indicator']->name,
            ]))
            ->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function filterByPerformance(Collection $rows, ?string $performance): Collection
    {
        if ($performance === null) {
            return $rows;
        }

        $label = match ($performance) {
            'on_track' => 'On track',
            'needs_attention' => 'Needs attention',
            'overachiever' => 'Overachiever',
            'not_assessed' => 'Not assessed',
            default => null,
        };

        return $label === null ? $rows : $rows->where('performance_status', $label)->values();
    }

    /** @param Collection<int, IndicatorTarget> $targets
     * @return array{type: string|null, value: float|null, minimum: float|null, maximum: float|null, status: string|null, display: string, numerator?: float|null, denominator?: float|null}
     */
    public function summarizeTargets(Collection $targets, IndicatorDefinition $indicator, ?int $expectedAssignmentCount = null): array
    {
        if (! $indicator->target_tracking) {
            return $this->aggregateTargets(collect(), $indicator->time_aggregation);
        }

        $targets = $targets->where('period_type', 'monthly')->where('active', true);
        if ($targets->isEmpty()) {
            return $this->missingTarget('Target not set');
        }
        if ($expectedAssignmentCount !== null && $targets->pluck('indicator_assignment_id')->unique()->count() < $expectedAssignmentCount) {
            return $this->missingTarget('Target missing for some projects');
        }

        $assignmentTargets = $targets->groupBy('indicator_assignment_id')->map(fn (Collection $group): array => [
            'target_data' => $this->aggregateTargets($group, $indicator->time_aggregation),
        ])->values();

        if ($assignmentTargets->count() === 1 || ($indicator->reporting_scope ?? 'project') === 'programme') {
            return $assignmentTargets->first()['target_data'];
        }

        $method = array_key_exists($indicator->centre_aggregation, $this->indicators->acrossProjectOptions($indicator->data_type, $indicator->calculation_method))
            ? $indicator->centre_aggregation
            : 'NON-AGGREGATABLE';

        return $this->consolidateTargets($assignmentTargets, $method);
    }

    /** @param Collection<int, IndicatorTarget> $targets
     * @return array{type: string|null, value: float|null, minimum: float|null, maximum: float|null, status: string|null, display: string, numerator?: float|null, denominator?: float|null}
     */
    private function aggregateTargets(Collection $targets, string $method): array
    {
        if ($targets->isEmpty()) {
            return $this->missingTarget('Not set');
        }

        $latest = $targets->sortBy('period')->last();
        if ($latest->target_type === 'ratio') {
            $numerator = (float) $targets->sum('target_numerator');
            $denominator = (float) $targets->sum('target_denominator');
            $value = $denominator > 0 ? $numerator / $denominator * 100 : null;

            return ['type' => 'ratio', 'value' => $value, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => $this->formatNumber($numerator).'/'.$this->formatNumber($denominator), 'numerator' => $numerator, 'denominator' => $denominator];
        }
        if ($latest->target_type === 'range') {
            $minimum = $method === 'SUM' ? (float) $targets->sum('minimum_value') : (float) $latest->minimum_value;
            $maximum = $method === 'SUM' ? (float) $targets->sum('maximum_value') : (float) $latest->maximum_value;

            return ['type' => 'range', 'value' => null, 'minimum' => $minimum, 'maximum' => $maximum, 'status' => null, 'display' => $this->formatNumber($minimum).'–'.$this->formatNumber($maximum)];
        }
        if ($latest->target_type === 'status') {
            $statuses = $targets->pluck('status_value')->unique();
            $status = $statuses->count() === 1 ? $statuses->first() : null;

            return ['type' => 'status', 'value' => null, 'minimum' => null, 'maximum' => null, 'status' => $status, 'display' => $status ?? 'Mixed targets'];
        }

        $values = $targets->pluck('value')->filter(fn ($value): bool => $value !== null)->map(fn ($value): float => (float) $value);
        $value = match ($method) {
            'SUM' => $values->sum(),
            'LATEST SNAPSHOT', 'NON-AGGREGATABLE', 'UNIQUE COUNT', 'STATUS' => $values->last(),
            default => $values->avg(),
        };

        return ['type' => 'numeric', 'value' => $value, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => $value === null ? 'Not set' : $this->formatNumber($value)];
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return array{type: string|null, value: float|null, minimum: float|null, maximum: float|null, status: string|null, display: string, numerator?: float|null, denominator?: float|null}
     */
    private function consolidateTargets(Collection $rows, string $method): array
    {
        if ($method === 'NON-AGGREGATABLE') {
            return $this->missingTarget('Select a project');
        }

        $targets = $rows->pluck('target_data')->filter(fn (array $target): bool => $target['type'] !== null);
        if ($targets->isEmpty()) {
            return $this->missingTarget('Not set');
        }
        if ($method === 'RATIO' || $targets->every(fn (array $target): bool => $target['type'] === 'ratio')) {
            $numerator = (float) $targets->sum(fn (array $target): float => (float) ($target['numerator'] ?? 0));
            $denominator = (float) $targets->sum(fn (array $target): float => (float) ($target['denominator'] ?? 0));
            $value = $denominator > 0 ? $numerator / $denominator * 100 : null;

            return ['type' => 'ratio', 'value' => $value, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => $this->formatNumber($numerator).'/'.$this->formatNumber($denominator), 'numerator' => $numerator, 'denominator' => $denominator];
        }
        if ($targets->every(fn (array $target): bool => $target['type'] === 'range')) {
            $minimum = $method === 'SUM' ? $targets->sum('minimum') : $targets->avg('minimum');
            $maximum = $method === 'SUM' ? $targets->sum('maximum') : $targets->avg('maximum');

            return ['type' => 'range', 'value' => null, 'minimum' => $minimum, 'maximum' => $maximum, 'status' => null, 'display' => $this->formatNumber($minimum).'–'.$this->formatNumber($maximum)];
        }
        if ($targets->every(fn (array $target): bool => $target['type'] === 'status')) {
            $statuses = $targets->pluck('status')->unique();
            $status = $statuses->count() === 1 ? $statuses->first() : null;

            return ['type' => 'status', 'value' => null, 'minimum' => null, 'maximum' => null, 'status' => $status, 'display' => $status ?? 'Mixed targets'];
        }

        $values = $targets->pluck('value')->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): float => (float) $value);
        $value = match ($method) {
            'SUM' => $values->sum(),
            'LATEST SNAPSHOT', 'UNIQUE COUNT', 'STATUS' => $values->last(),
            default => $values->avg(),
        };

        return ['type' => 'numeric', 'value' => $value, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => $value === null ? 'Not set' : $this->formatNumber($value)];
    }

    /** @return array{variance: float|null, percentage: float|null, status: string} */
    private function performance(float|array|string|null $achievement, array $target, string $direction): array
    {
        if ($target['type'] === 'range' && is_numeric($achievement)) {
            $met = (float) $achievement >= $target['minimum'] && (float) $achievement <= $target['maximum'];

            return ['variance' => null, 'percentage' => null, 'status' => $met ? 'On track' : 'Needs attention'];
        }
        if ($target['type'] === 'status') {
            if ($target['status'] === null || $achievement === null) {
                return ['variance' => null, 'percentage' => null, 'status' => 'Not assessed'];
            }
            $met = is_string($achievement)
                ? strcasecmp($achievement, $target['status']) === 0
                : (is_array($achievement) && count($achievement) === 1 && array_key_exists($target['status'], $achievement));
            if (is_numeric($achievement) && in_array($target['status'], ['Yes', 'No'], true)) {
                $met = (bool) $achievement === ($target['status'] === 'Yes');
            }

            return ['variance' => null, 'percentage' => null, 'status' => $met ? 'On track' : 'Needs attention'];
        }
        if (! is_numeric($achievement) || ! is_numeric($target['value'])) {
            return ['variance' => null, 'percentage' => null, 'status' => 'Not assessed'];
        }

        $achievementValue = (float) $achievement;
        $targetValue = (float) $target['value'];
        $variance = $achievementValue - $targetValue;
        $percentage = $targetValue == 0.0 ? null : round((($achievementValue / $targetValue) * 100) - 100, 4);
        if ($targetValue == 0.0) {
            $status = match (true) {
                $achievementValue == 0.0 => 'On track',
                $direction === 'lower' => 'Needs attention',
                default => 'Not assessed',
            };

            return ['variance' => $variance, 'percentage' => null, 'status' => $status];
        }

        $directionAdjustedPercentage = $direction === 'lower' ? -$percentage : $percentage;
        $threshold = (float) config('impact.variance_explanation_threshold', 10);
        $status = match (true) {
            $directionAdjustedPercentage < -$threshold => 'Needs attention',
            $directionAdjustedPercentage > $threshold => 'Overachiever',
            default => 'On track',
        };

        return ['variance' => $variance, 'percentage' => $percentage, 'status' => $status];
    }

    /** @return array{type: null, value: null, minimum: null, maximum: null, status: null, display: string} */
    private function missingTarget(string $display): array
    {
        return ['type' => null, 'value' => null, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => $display];
    }

    private function formatNumber(float|int $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    private function categoryOrder(string $category): int
    {
        $order = array_search($category, config('impact.categories'), true);

        return $order === false ? 99 : $order;
    }
}
