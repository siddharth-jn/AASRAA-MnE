<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntryRevision extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + ['json_value' => 'array', 'date_value' => 'date', 'boolean_value' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(IndicatorEntry::class, 'indicator_entry_id');
    }

    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(IndicatorDefinitionVersion::class, 'indicator_definition_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceFile::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class);
    }

    public function displayValue(): string
    {
        if ($this->value_state === 'not_applicable') {
            return 'Not applicable';
        }

        return $this->numeric_value !== null ? rtrim(rtrim(number_format((float) $this->numeric_value, 2, '.', ','), '0'), '.') : ($this->text_value ?? $this->date_value?->toDateString() ?? ($this->boolean_value !== null ? ($this->boolean_value ? 'Yes' : 'No') : implode(', ', $this->json_value ?? [])));
    }
}
