<?php

namespace App\Services;

use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;

class VarianceService
{
    /** @param array<string, mixed> $data
     * @return array{percentage: float|null, flagged: bool}
     */
    public function evaluate(IndicatorDefinition $indicator, ?IndicatorTarget $target, array $data): array
    {
        if ($target === null || ($data['value_state'] ?? 'reported') !== 'reported') {
            return ['percentage' => null, 'flagged' => false];
        }

        if ($target->target_type === 'range' && isset($data['numeric_value'])) {
            $value = (float) $data['numeric_value'];

            return [
                'percentage' => null,
                'flagged' => $value < (float) $target->minimum_value || $value > (float) $target->maximum_value,
            ];
        }

        if ($target->target_type === 'status') {
            $actualStatus = $indicator->data_type === 'boolean' && array_key_exists('boolean_value', $data)
                ? ((bool) $data['boolean_value'] ? 'Yes' : 'No')
                : ($data['text_value'] ?? null);

            return [
                'percentage' => null,
                'flagged' => $actualStatus !== null && strcasecmp((string) $actualStatus, (string) $target->status_value) !== 0,
            ];
        }

        if (! isset($data['numeric_value']) || $target->value === null) {
            return ['percentage' => null, 'flagged' => false];
        }

        $actual = (float) $data['numeric_value'];
        $targetValue = (float) $target->value;
        if ($targetValue === 0.0) {
            return ['percentage' => null, 'flagged' => $actual !== 0.0];
        }

        $percentage = (($actual / $targetValue) * 100) - 100;

        return [
            'percentage' => round($percentage, 4),
            'flagged' => abs($percentage) >= (float) config('impact.variance_explanation_threshold', 10),
        ];
    }
}
