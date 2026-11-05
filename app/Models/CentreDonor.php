<?php

namespace App\Models;

use Database\Factories\CentreDonorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentreDonor extends ImpactModel
{
    /** @use HasFactory<CentreDonorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return parent::casts() + [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_primary' => 'boolean',
        ];
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }
}
