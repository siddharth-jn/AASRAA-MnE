<?php

namespace App\Models;

class AuditLog extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + ['changes' => 'array'];
    }
}
