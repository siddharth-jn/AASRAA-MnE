<?php

namespace App\Services;

use App\Models\IndicatorEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReportingService
{
    public function __construct(private readonly ScopeService $scope, private readonly AggregationService $aggregation) {}

    /** @return Collection<int, array<string, mixed>> */
    public function report(User $user, CarbonImmutable $start, CarbonImmutable $end, ?int $projectId = null, ?int $centreId = null): Collection
    {
        $query = $this->scope->entries($user)->whereNotNull('approved_revision_id')->whereBetween('period', [$start, $end])->with(['assignment.indicator.project', 'assignment.centre', 'approvedRevision']);
        if ($projectId) {
            $query->whereHas('assignment.indicator', fn ($builder) => $builder->where('project_id', $projectId));
        }
        if ($centreId) {
            $query->whereHas('assignment', fn ($builder) => $builder->where('centre_id', $centreId));
        }

        return $query->get()->groupBy(fn (IndicatorEntry $entry): string => $entry->assignment->indicator->id.'-'.$entry->assignment->centre_id)
            ->map(function (Collection $entries): array {
                $first = $entries->first();
                $indicator = $first->assignment->indicator;
                $records = $entries->sortBy('period')->map(fn (IndicatorEntry $entry): array => ['value' => $entry->approvedRevision->numeric_value, 'numerator' => $entry->approvedRevision->numerator, 'denominator' => $entry->approvedRevision->denominator, 'status' => $entry->approvedRevision->text_value])->all();

                return ['indicator' => $indicator, 'project' => $indicator->project, 'centre' => $first->assignment->centre, 'achievement' => $this->aggregation->aggregate($records, $indicator->time_aggregation), 'months' => $entries->count(), 'status' => 'APPROVED'];
            })->values();
    }
}
