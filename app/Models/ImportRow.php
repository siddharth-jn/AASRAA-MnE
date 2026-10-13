<?php

namespace App\Models;

class ImportRow extends ImpactModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return parent::casts() + ['source' => 'array', 'normalized' => 'array'];
    }
}
