<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndicatorImportBatch extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + ['summary' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(IndicatorImportRow::class);
    }
}
