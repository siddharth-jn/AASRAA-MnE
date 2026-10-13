<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndicatorDefinition extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + ['options' => 'array', 'allowed_extensions' => 'array', 'evidence_required' => 'boolean', 'effective_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function centres(): BelongsToMany
    {
        return $this->belongsToMany(Centre::class, 'indicator_assignments')->withPivot('required', 'is_demo');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(IndicatorAssignment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(IndicatorDefinitionVersion::class);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saved(function (self $indicator): void {
            if ($indicator->wasRecentlyCreated || $indicator->wasChanged()) {
                $indicator->versions()->create(['version' => ($indicator->versions()->max('version') ?? 0) + 1, 'definition' => $indicator->attributesToArray(), 'is_demo' => (bool) $indicator->is_demo]);
            }
        });
    }
}
