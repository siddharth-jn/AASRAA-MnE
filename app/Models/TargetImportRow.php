<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetImportRow extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + [
            'period' => 'date',
            'previous_target' => 'array',
            'proposed_target' => 'array',
            'affects_entry' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TargetImportBatch::class, 'target_import_batch_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(IndicatorAssignment::class, 'indicator_assignment_id');
    }
}
