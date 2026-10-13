<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends ImpactModel
{
    use SoftDeletes;

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function centres(): BelongsToMany
    {
        return $this->belongsToMany(Centre::class, 'project_centres')->withPivot('is_demo', 'provenance');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user_assignments');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(IndicatorDefinition::class);
    }
}
