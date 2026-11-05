<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndicatorTarget extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + [
            'period' => 'date',
            'period_end' => 'date',
            'value' => 'decimal:4',
            'minimum_value' => 'decimal:4',
            'maximum_value' => 'decimal:4',
            'target_numerator' => 'decimal:4',
            'target_denominator' => 'decimal:4',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(IndicatorAssignment::class, 'indicator_assignment_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(IndicatorTargetRevision::class);
    }
}
