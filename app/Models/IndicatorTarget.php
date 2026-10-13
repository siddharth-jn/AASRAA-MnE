<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorTarget extends ImpactModel
{
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(IndicatorAssignment::class, 'indicator_assignment_id');
    }
}
