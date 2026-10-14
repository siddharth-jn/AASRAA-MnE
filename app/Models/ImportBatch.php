<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + ['columns' => 'array', 'mapping' => 'array'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
