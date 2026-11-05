<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\Donor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DonorService
{
    public function applyCentreFilter(Builder $query, string $donorName, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->whereHas('centre', fn (Builder $centreQuery): Builder => $this->applyToCentres($centreQuery, $donorName, $start, $end));
    }

    public function applyToCentres(Builder $query, string $donorName, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where(function (Builder $donorQuery) use ($donorName, $start, $end): void {
            $donorQuery->whereHas('donorAssignments', function (Builder $assignmentQuery) use ($donorName, $start, $end): void {
                $assignmentQuery->whereDate('starts_on', '<=', $end)
                    ->where(fn (Builder $dates): Builder => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
                    ->whereHas('donor', fn (Builder $donor): Builder => $donor->where('active', true)->where('name', $donorName));
            })->orWhere(function (Builder $fallbackQuery) use ($donorName, $start, $end): void {
                $fallbackQuery->where('source_donor_name', $donorName)
                    ->whereDoesntHave('donorAssignments', function (Builder $assignmentQuery) use ($start, $end): void {
                        $assignmentQuery->whereDate('starts_on', '<=', $end)
                            ->where(fn (Builder $dates): Builder => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
                            ->whereHas('donor', fn (Builder $donor): Builder => $donor->where('active', true));
                    });
            });
        });
    }

    /** @return Collection<int, string> */
    public function namesForPeriod(?Centre $centre, CarbonInterface $start, CarbonInterface $end): Collection
    {
        if ($centre === null) {
            return collect();
        }

        $assignments = $centre->donorAssignments
            ->filter(fn ($assignment): bool => $assignment->starts_on->lte($end) && ($assignment->ends_on === null || $assignment->ends_on->gte($start)))
            ->filter(fn ($assignment): bool => (bool) $assignment->donor?->active)
            ->sortByDesc('is_primary')
            ->pluck('donor.name')
            ->filter()
            ->unique()
            ->values();

        if ($assignments->isNotEmpty()) {
            return $assignments;
        }

        return filled($centre->source_donor_name) ? collect([$centre->source_donor_name]) : collect();
    }

    /** @return Collection<int, string> */
    public function availableNames(Builder $centreScope, CarbonInterface $start, CarbonInterface $end): Collection
    {
        $localNames = Donor::query()
            ->where('active', true)
            ->whereHas('centreDonors', fn (Builder $query): Builder => $query
                ->whereIn('centre_id', (clone $centreScope)->select('centres.id'))
                ->whereDate('starts_on', '<=', $end)
                ->where(fn (Builder $dates): Builder => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start)))
            ->pluck('name');
        $sourceNames = (clone $centreScope)
            ->whereNotNull('source_donor_name')
            ->whereDoesntHave('donorAssignments', fn (Builder $query): Builder => $query
                ->whereDate('starts_on', '<=', $end)
                ->where(fn (Builder $dates): Builder => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
                ->whereHas('donor', fn (Builder $donor): Builder => $donor->where('active', true)))
            ->pluck('source_donor_name');

        return $localNames->merge($sourceNames)->filter()->unique()->sort()->values();
    }
}
