<?php

namespace App\Models;

use Database\Factories\SourceSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceSyncRun extends ImpactModel
{
    /** @use HasFactory<SourceSyncRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return parent::casts() + [
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
