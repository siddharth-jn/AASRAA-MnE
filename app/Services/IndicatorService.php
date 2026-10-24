<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class IndicatorService
{
    /** @return array<string, string> */
    public function acrossProjectOptions(string $dataType): array
    {
        return match ($dataType) {
            'integer', 'decimal', 'boolean' => ['SUM' => 'Total (sum)', 'AVERAGE' => 'Average', 'NON-AGGREGATABLE' => 'Do not combine'],
            'percentage' => ['AVERAGE' => 'Average percentage', 'NON-AGGREGATABLE' => 'Do not combine'],
            'ratio', 'calculated' => ['RATIO' => 'Ratio from combined totals'],
            'single_select', 'status' => ['STATUS' => 'Count each status', 'NON-AGGREGATABLE' => 'Do not combine'],
            default => ['NON-AGGREGATABLE' => 'Do not combine'],
        };
    }

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
        if (in_array($dataType, ['single_select', 'status'], true) && ($data['centre_aggregation'] ?? null) === 'STATUS') {
            $data['time_aggregation'] = 'STATUS';
        }

        if (isset($data['options']) && is_array($data['options'])) {
            $data['options'] = array_values(array_unique(array_filter(array_map('trim', $data['options']))));
        }

        if (($data['target_tracking'] ?? false) && ! in_array($dataType, app(TargetService::class)->supportedDataTypes(), true)) {
            $data['target_tracking'] = false;
        }
        if (($data['target_tracking'] ?? false) && in_array($dataType, ['boolean', 'single_select', 'status'], true)) {
            $data['direction'] = 'status';
        }

        if (empty($data['allowed_extensions'])) {
            $data['allowed_extensions'] = config('impact.evidence_extensions');
        }

        $errors = [];
        if (($data['target_tracking'] ?? false) && in_array($dataType, ['integer', 'decimal', 'percentage', 'ratio', 'calculated'], true) && ! in_array($data['direction'] ?? null, ['higher', 'lower', 'range'], true)) {
            $errors['direction'] = 'Choose whether a higher, lower, or in-range result meets the target.';
        }
        if (is_numeric($data['min_value'] ?? null) && is_numeric($data['max_value'] ?? null) && (float) $data['min_value'] > (float) $data['max_value']) {
            $errors['max_value'] = 'The maximum value must be greater than or equal to the minimum value.';
        }
        if (filled($data['minimum_date'] ?? null) && filled($data['maximum_date'] ?? null) && $data['minimum_date'] > $data['maximum_date']) {
            $errors['maximum_date'] = 'The maximum date must be on or after the minimum date.';
        }
        if (in_array($dataType, ['single_select', 'multi_select', 'status'], true) && empty($data['options'])) {
            $errors['options'] = 'Add at least one accepted option for this input type.';
        }
        if (array_key_exists('centre_aggregation', $data) && ! array_key_exists((string) $data['centre_aggregation'], $this->acrossProjectOptions($dataType))) {
            $errors['centre_aggregation'] = 'Choose an across-project method supported by this input type.';
        }
        $data['evidence_review_required'] = false;
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
