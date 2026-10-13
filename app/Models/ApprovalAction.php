<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalAction extends ImpactModel
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
