<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Centre extends ImpactModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'source_project_id' => 'integer',
            'source_donor_id' => 'integer',
            'source_started_date' => 'date',
            'source_synced_at' => 'datetime',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_centres')->withPivot('is_demo', 'provenance');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'centre_user_assignments');
    }
}
