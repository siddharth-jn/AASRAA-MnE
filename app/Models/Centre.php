<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Centre extends ImpactModel
{
    use SoftDeletes;

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_centres')->withPivot('is_demo', 'provenance');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'centre_user_assignments');
    }
}
