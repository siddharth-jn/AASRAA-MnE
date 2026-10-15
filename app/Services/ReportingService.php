<?php

namespace App\Services;

use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReportingService
{
    public function __construct(private readonly ScopeService $scope, private readonly AggregationService $aggregation) {}

    /** @return Collection<int, array<string, mixed>> */
    public function report(
        User $user,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $projectId = null,
        ?int $centreId = null,
        ?int $indicatorId = null,
        ?string $category = null,
    ): Collection {
        if ($end->lt($start)) {
            return collect();
        }

        $query = $this->scope->entries($user)
            ->whereNotNull('approved_revision_id')
            ->whereBetween('period', [$start, $end])
            ->with(['assignment.indicator.project', 'assignment.centre', 'approvedRevision']);
        if ($projectId) {
            $query->whereHas('assignment.indicator', fn ($builder) => $builder->where('project_id', $projectId));
        }
        if ($centreId) {
            $query->whereHas('assignment', fn ($builder) => $builder->where('centre_id', $centreId));
        }
        if ($indicatorId) {
            $query->whereHas('assignment', fn ($builder) => $builder->where('indicator_definition_id', $indicatorId));
        }
        if ($category) {
            $query->whereHas('assignment.indicator', fn ($builder) => $builder->where('category', $category));
        }

        $entries = $query->get();
        $targets = IndicatorTarget::query()
            ->whereIn('indicator_assignment_id', $entries->pluck('indicator_assignment_id')->unique())
            ->where('active', true)
            ->whereDate('period', '<=', $end)
            ->whereDate('period_end', '>=', $start)
            ->get()
            ->groupBy('indicator_assignment_id');

        return $entries
            ->groupBy(fn (IndicatorEntry $entry): string => $entry->assignment->indicator->id.'-'.$entry->assignment->centre_id)
            ->map(function (Collection $group) use ($targets): array {
                /** @var IndicatorEntry $first */
                $first = $group->first();
                $indicator = $first->assignment->indicator;
                $records = $group->sortBy('period')->map(fn (IndicatorEntry $entry): array => [
                    'value' => $entry->approvedRevision->numeric_value ?? ($entry->approvedRevision->boolean_value === null ? null : (int) $entry->approvedRevision->boolean_value),
                    'numerator' => $entry->approvedRevision->numerator,
                    'denominator' => $entry->approvedRevision->denominator,
                    'weight' => $entry->approvedRevision->weight,
                    'status' => $entry->approvedRevision->text_value,
                ])->all();
                $achievement = $this->aggregation->aggregate($records, $indicator->time_aggregation);
                $targetData = $this->aggregateTargets($targets->get($first->indicator_assignment_id, collect()), $indicator->time_aggregation);
                $performance = $this->performance($achievement, $targetData, $indicator->direction);

                return [
                    'indicator' => $indicator,
                    'project' => $indicator->project,
                    'centre' => $first->assignment->centre,
                    'achievement' => $achievement,
                    'target' => $targetData['value'],
                    'target_data' => $targetData,
                    'target_display' => $targetData['display'],
                    'variance' => $performance['variance'],
                    'achievement_percentage' => $performance['percentage'],
                    'performance_status' => $performance['status'],
                    'months' => $group->count(),
                    'status' => 'APPROVED',
                    'numerator' => collect($records)->sum('numerator'),
                    'denominator' => collect($records)->sum('denominator'),
                    'weight' => collect($records)->sum('weight'),
                    'trend' => $group->sortBy('period')->map(fn (IndicatorEntry $entry): array => [
                        'period' => $entry->period->format('Y-m-d'),
                        'value' => $entry->approvedRevision->numeric_value,
                    ])->values(),
                ];
            })
            ->sortBy(fn (array $row): string => $row['project']->name.$row['centre']->name.$row['indicator']->name)
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
    ): Collection {
        return $this->report($user, $start, $end, $projectId, null, $indicatorId, $category)
            ->groupBy(fn (array $row): int => $row['indicator']->id)
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $indicator = $first['indicator'];
                $records = $rows->map(fn (array $row): array => [
                    'value' => is_numeric($row['achievement']) ? (float) $row['achievement'] : null,
                    'numerator' => $row['numerator'],
                    'denominator' => $row['denominator'],
                    'weight' => $row['weight'],
                    'status' => is_string($row['achievement']) ? $row['achievement'] : null,
                ])->all();
                $achievement = $this->aggregation->aggregate($records, $indicator->centre_aggregation);
                $targetData = $this->consolidateTargets($rows, $indicator->centre_aggregation);
                $performance = $this->performance($achievement, $targetData, $indicator->direction);

                return array_replace($first, [
                    'centre' => null,
                    'achievement' => $achievement,
                    'target' => $targetData['value'],
                    'target_data' => $targetData,
                    'target_display' => $targetData['display'],
                    'variance' => $performance['variance'],
                    'achievement_percentage' => $performance['percentage'],
                    'performance_status' => $performance['status'],
                    'months' => $rows->sum('months'),
                    'numerator' => $rows->sum('numerator'),
                    'denominator' => $rows->sum('denominator'),
                    'weight' => $rows->sum('weight'),
                ]);
            })
            ->sortBy(fn (array $row): string => $row['indicator']->category.$row['indicator']->name)
            ->values();
    }

    /**
     * @param  Collection<int, IndicatorTarget>  $targets
     * @return array{type: string|null, value: float|null, minimum: float|null, maximum: float|null, status: string|null, display: string}
     */
    public function summarizeTargets(Collection $targets, IndicatorDefinition $indicator): array
    {
        if ($targets->isEmpty()) {
            return $this->aggregateTargets(collect(), $indicator->time_aggregation);
        }

        $assignmentTargets = $targets
            ->groupBy('indicator_assignment_id')
            ->map(fn (Collection $group): array => [
                'target_data' => $this->aggregateTargets($group, $indicator->time_aggregation),
            ])
            ->values();

        if ($assignmentTargets->count() === 1) {
            return $assignmentTargets->first()['target_data'];
        }

        return $this->consolidateTargets($assignmentTargets, $indicator->centre_aggregation);
    }

    /** @param Collection<int, IndicatorTarget> $targets
     * @return array{type: string|null, value: float|null, minimum: float|null, maximum: float|null, status: string|null, display: string}
     */
    private function aggregateTargets(Collection $targets, string $method): array
    {
        if ($targets->isEmpty()) {
            return ['type' => null, 'value' => null, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => 'Not set'];
        }

        $latest = $targets->sortBy('period')->last();
        if ($latest->target_type === 'range') {
            return ['type' => 'range', 'value' => null, 'minimum' => (float) $latest->minimum_value, 'maximum' => (float) $latest->maximum_value, 'status' => null, 'display' => $latest->minimum_value.'–'.$latest->maximum_value];
        }
        if ($latest->target_type === 'status') {
            return ['type' => 'status', 'value' => null, 'minimum' => null, 'maximum' => null, 'status' => $latest->status_value, 'display' => (string) $latest->status_value];
        }

        $values = $targets->pluck('value')->filter(fn ($value): bool => $value !== null)->map(fn ($value): float => (float) $value);
        $value = match ($method) {
            'SUM' => $values->sum(),
            'LATEST SNAPSHOT', 'NON-AGGREGATABLE', 'UNIQUE COUNT', 'STATUS' => $values->last(),
            default => $values->avg(),
        };

        return ['type' => 'numeric', 'value' => $value, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => $value === null ? 'Not set' : rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.')];
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return array{type: string|null, value: float|null, minimum: float|null, maximum: float|null, status: string|null, display: string}
     */
    private function consolidateTargets(Collection $rows, string $method): array
    {
        $targets = $rows->pluck('target_data')->filter(fn (array $target): bool => $target['type'] !== null);
        if ($targets->isEmpty()) {
            return ['type' => null, 'value' => null, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => 'Not set'];
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
        if ($values->isEmpty()) {
            return ['type' => null, 'value' => null, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => 'Not set'];
        }
        $value = match ($method) {
            'SUM' => $values->sum(),
            'LATEST SNAPSHOT', 'NON-AGGREGATABLE', 'UNIQUE COUNT', 'STATUS' => $values->last(),
            default => $values->avg(),
        };

        return ['type' => 'numeric', 'value' => $value, 'minimum' => null, 'maximum' => null, 'status' => null, 'display' => $value === null ? 'Not set' : $this->formatNumber($value)];
    }

    /** @return array{variance: float|null, percentage: float|null, status: string} */
    private function performance(float|array|string|null $achievement, array $target, string $direction): array
    {
        if ($target['type'] === 'range' && is_numeric($achievement)) {
            $met = (float) $achievement >= $target['minimum'] && (float) $achievement <= $target['maximum'];

            return ['variance' => null, 'percentage' => null, 'status' => $met ? 'Met' : 'Outside range'];
        }
        if ($target['type'] === 'status') {
            if ($target['status'] === null) {
                return ['variance' => null, 'percentage' => null, 'status' => 'Review'];
            }
            $met = is_string($achievement)
                ? strcasecmp($achievement, $target['status']) === 0
                : (is_array($achievement) && count($achievement) === 1 && array_key_exists($target['status'], $achievement));

            return ['variance' => null, 'percentage' => null, 'status' => $met ? 'Met' : 'Status differs'];
        }
        if (! is_numeric($achievement) || ! is_numeric($target['value'])) {
            return ['variance' => null, 'percentage' => null, 'status' => $target['type'] === null ? 'No target' : 'Review'];
        }

        $achievementValue = (float) $achievement;
        $targetValue = (float) $target['value'];
        $variance = $achievementValue - $targetValue;
        $percentage = match ($direction) {
            'lower' => $achievementValue == 0.0 ? ($targetValue >= 0 ? 100.0 : null) : $targetValue / $achievementValue * 100,
            default => $targetValue == 0.0 ? null : $achievementValue / $targetValue * 100,
        };
        $met = match ($direction) {
            'lower' => $achievementValue <= $targetValue,
            default => $achievementValue >= $targetValue,
        };

        return ['variance' => $variance, 'percentage' => $percentage, 'status' => $met ? 'Met' : ($direction === 'lower' ? 'Above target' : 'Below target')];
    }

    private function formatNumber(float|int $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
