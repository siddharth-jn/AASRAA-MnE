<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends ImpactModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return parent::casts() + ['source' => 'array', 'normalized' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
