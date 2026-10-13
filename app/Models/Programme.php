<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Programme extends ImpactModel
{
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
