<?php

namespace App\Models;

class IndicatorDefinitionVersion extends ImpactModel
{
    protected function casts(): array
    {
        return parent::casts() + ['definition' => 'array'];
    }
}
