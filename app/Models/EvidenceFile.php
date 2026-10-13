<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceFile extends ImpactModel
{
    public function revision(): BelongsTo
    {
        return $this->belongsTo(EntryRevision::class, 'entry_revision_id');
    }
}
