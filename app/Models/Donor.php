<?php

namespace App\Models;

use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Donor extends ImpactModel
{
    /** @use HasFactory<DonorFactory> */
    use HasFactory;

    public function centreDonors(): HasMany
    {
        return $this->hasMany(CentreDonor::class);
    }

    public function centres(): BelongsToMany
    {
        return $this->belongsToMany(Centre::class, 'centre_donors')
            ->withPivot(['starts_on', 'ends_on', 'is_primary', 'notes', 'is_demo'])
            ->withTimestamps();
    }
}
