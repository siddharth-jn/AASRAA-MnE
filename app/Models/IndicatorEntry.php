<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndicatorEntry extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + ['period' => 'date'];
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
}
