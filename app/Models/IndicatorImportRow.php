<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorImportRow extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + [
            'source' => 'array',
            'proposed_indicator' => 'array',
            'centre_ids' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IndicatorImportBatch::class, 'indicator_import_batch_id');
    }
}
