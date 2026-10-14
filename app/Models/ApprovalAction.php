<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalAction extends ImpactModel
{
    public function revision(): BelongsTo
    {
        return $this->belongsTo(EntryRevision::class, 'entry_revision_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
