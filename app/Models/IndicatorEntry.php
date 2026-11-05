<?php

namespace App\Models;

use App\Services\IndicatorReportingPeriodService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class IndicatorEntry extends ImpactModel
{
    public function scopeTargetBacked(Builder $query): Builder
    {
        return $query->whereExists(function (QueryBuilder $targetQuery): void {
            $targetQuery->selectRaw('1')
                ->from('indicator_targets')
                ->whereColumn('indicator_targets.indicator_assignment_id', 'indicator_entries.indicator_assignment_id')
                ->whereColumn('indicator_targets.period', 'indicator_entries.period')
                ->where('indicator_targets.period_type', 'monthly')
                ->where('indicator_targets.active', true);
        });
    }

    protected function casts(): array
    {
        return parent::casts() + [
            'period' => 'date',
            'target_changed_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(IndicatorAssignment::class, 'indicator_assignment_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(EntryRevision::class, 'current_revision_id');
    }

    public function approvedRevision(): BelongsTo
    {
        return $this->belongsTo(EntryRevision::class, 'approved_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EntryRevision::class);
    }

    public function periodLabel(): string
    {
        $indicator = $this->assignment->indicator;

        return app(IndicatorReportingPeriodService::class)->label($this->period, $indicator->frequency);
    }
}
