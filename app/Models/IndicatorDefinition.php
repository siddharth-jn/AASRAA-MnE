<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class IndicatorDefinition extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + [
            'options' => 'array',
            'allowed_extensions' => 'array',
            'evidence_required' => 'boolean',
            'evidence_review_required' => 'boolean',
            'approval_required' => 'boolean',
            'value_required' => 'boolean',
            'target_tracking' => 'boolean',
            'effective_date' => 'date',
            'minimum_date' => 'date',
            'maximum_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function centres(): BelongsToMany
    {
        return $this->belongsToMany(Centre::class, 'indicator_assignments')->withPivot('required', 'is_demo');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(IndicatorAssignment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(IndicatorDefinitionVersion::class);
    }

    public function targets(): HasManyThrough
    {
        return $this->hasManyThrough(IndicatorTarget::class, IndicatorAssignment::class);
    }

    public function isEffectiveForPeriod(CarbonInterface|string $period): bool
    {
        $reportingMonth = $period instanceof CarbonInterface
            ? CarbonImmutable::instance($period)->startOfMonth()
            : CarbonImmutable::parse($period)->startOfMonth();

        return $reportingMonth->gte(CarbonImmutable::parse($this->effective_date)->startOfMonth());
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saved(function (self $indicator): void {
            if ($indicator->wasRecentlyCreated || $indicator->wasChanged()) {
                $indicator->versions()->create(['version' => ($indicator->versions()->max('version') ?? 0) + 1, 'definition' => $indicator->attributesToArray(), 'is_demo' => (bool) $indicator->is_demo]);
            }
        });
    }
}
