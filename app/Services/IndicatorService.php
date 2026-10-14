<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class IndicatorService
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function prepareAndValidate(array $data): array
    {
        $dataType = (string) ($data['data_type'] ?? '');

        if ($dataType === 'percentage') {
            $data['min_value'] ??= 0;
            $data['max_value'] ??= 100;
        }

        if ($dataType === 'integer') {
            $data['precision'] = 0;
        }

        if (in_array($dataType, ['ratio', 'calculated'], true)) {
            $data['calculation_method'] = 'ratio_percentage';
            $data['time_aggregation'] = 'RATIO';
            $data['centre_aggregation'] = 'RATIO';
            $data['numerator_label'] = filled($data['numerator_label'] ?? null) ? $data['numerator_label'] : 'Numerator';
            $data['denominator_label'] = filled($data['denominator_label'] ?? null) ? $data['denominator_label'] : 'Denominator';
        }

        if (isset($data['options']) && is_array($data['options'])) {
            $data['options'] = array_values(array_unique(array_filter(array_map('trim', $data['options']))));
        }

        $errors = [];
        if (is_numeric($data['min_value'] ?? null) && is_numeric($data['max_value'] ?? null) && (float) $data['min_value'] > (float) $data['max_value']) {
            $errors['max_value'] = 'The maximum value must be greater than or equal to the minimum value.';
        }
        if (filled($data['minimum_date'] ?? null) && filled($data['maximum_date'] ?? null) && $data['minimum_date'] > $data['maximum_date']) {
            $errors['maximum_date'] = 'The maximum date must be on or after the minimum date.';
        }
        if (in_array($dataType, ['single_select', 'multi_select', 'status'], true) && empty($data['options'])) {
            $errors['options'] = 'Add at least one accepted option for this input type.';
        }
        if (($data['evidence_required'] ?? false) && (int) ($data['min_files'] ?? 0) < 1) {
            $data['min_files'] = 1;
        }
        if ((int) ($data['min_files'] ?? 0) > (int) ($data['max_files'] ?? 0)) {
            $errors['min_files'] = 'The minimum file count cannot exceed the maximum file count.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
