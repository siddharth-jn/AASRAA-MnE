<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndicatorAssignment extends ImpactModel
{
    public $timestamps = false;

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(IndicatorDefinition::class, 'indicator_definition_id');
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(IndicatorEntry::class);
    }
}
