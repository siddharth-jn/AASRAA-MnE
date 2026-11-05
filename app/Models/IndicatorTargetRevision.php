<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorTargetRevision extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + [
            'value' => 'decimal:4',
            'minimum_value' => 'decimal:4',
            'maximum_value' => 'decimal:4',
            'target_numerator' => 'decimal:4',
            'target_denominator' => 'decimal:4',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(IndicatorTarget::class, 'indicator_target_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
