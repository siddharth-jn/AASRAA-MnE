<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class IndicatorService
{
    /** @return array<string, string> */
    public function timeAggregationOptions(string $dataType, ?string $calculationMethod = null): array
    {
        if (in_array($dataType, ['ratio', 'calculated'], true)
            || ($dataType === 'percentage' && $calculationMethod === 'ratio_percentage')) {
            return ['RATIO' => 'Ratio from combined monthly numerators and denominators'];
        }

        return match ($dataType) {
            'integer' => [
                'SUM' => 'Total across months',
                'LATEST SNAPSHOT' => 'Latest monthly value',
                'AVERAGE' => 'Average monthly value',
                'UNIQUE COUNT' => 'Cumulative unique total (latest monthly snapshot)',
                'NON-AGGREGATABLE' => 'Do not combine across months',
            ],
            'decimal' => [
                'SUM' => 'Total across months',
                'LATEST SNAPSHOT' => 'Latest monthly value',
                'AVERAGE' => 'Average monthly value',
                'NON-AGGREGATABLE' => 'Do not combine across months',
            ],
            'percentage' => [
                'AVERAGE' => 'Average monthly percentage',
                'LATEST SNAPSHOT' => 'Latest monthly percentage',
                'NON-AGGREGATABLE' => 'Do not combine across months',
            ],
            'boolean', 'date' => [
                'LATEST SNAPSHOT' => 'Latest monthly value',
                'NON-AGGREGATABLE' => 'Do not combine across months',
            ],
            'single_select', 'status' => ['STATUS' => 'Count each status'],
            default => ['NON-AGGREGATABLE' => 'Do not combine across months'],
        };
    }

    /** @return array<string, string> */
    public function acrossProjectOptions(string $dataType, ?string $calculationMethod = null): array
    {
        if ($dataType === 'percentage' && $calculationMethod === 'ratio_percentage') {
            return ['RATIO' => 'Ratio from combined numerators and denominators', 'NON-AGGREGATABLE' => 'Do not combine'];
        }

        return match ($dataType) {
            'integer', 'decimal', 'boolean' => ['SUM' => 'Total (sum)', 'AVERAGE' => 'Average', 'NON-AGGREGATABLE' => 'Do not combine'],
            'percentage' => ['AVERAGE' => 'Average percentage', 'NON-AGGREGATABLE' => 'Do not combine'],
            'ratio', 'calculated' => ['RATIO' => 'Ratio from combined totals', 'NON-AGGREGATABLE' => 'Do not combine'],
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
        $data['frequency'] = 'monthly';
        $data['value_required'] = true;
        $data['reporting_scope'] = in_array($data['reporting_scope'] ?? null, ['project', 'programme'], true)
            ? $data['reporting_scope']
            : 'project';

        if ($dataType === 'percentage') {
            $data['min_value'] ??= 0;
            $data['max_value'] ??= 100;
        }

        if ($dataType === 'integer') {
            $data['precision'] = 0;
        }

        if (in_array($dataType, ['ratio', 'calculated'], true)
            || ($dataType === 'percentage' && ($data['calculation_method'] ?? null) === 'ratio_percentage')) {
            $data['calculation_method'] = 'ratio_percentage';
            $data['time_aggregation'] = 'RATIO';
            if (($data['centre_aggregation'] ?? null) !== 'NON-AGGREGATABLE') {
                $data['centre_aggregation'] = 'RATIO';
            }
            $data['numerator_label'] = filled($data['numerator_label'] ?? null) ? $data['numerator_label'] : 'Numerator';
            $data['denominator_label'] = filled($data['denominator_label'] ?? null) ? $data['denominator_label'] : 'Denominator';
        }
        if ($dataType === 'percentage' && blank($data['calculation_method'] ?? null)) {
            $data['calculation_method'] = 'direct_percentage';
        }
        if ($data['reporting_scope'] === 'programme') {
            $data['centre_aggregation'] = 'NON-AGGREGATABLE';
        }
        if (in_array($dataType, ['single_select', 'status'], true) && ($data['centre_aggregation'] ?? null) === 'STATUS') {
            $data['time_aggregation'] = 'STATUS';
        }

        $data['time_aggregation'] ??= array_key_first($this->timeAggregationOptions($dataType, $data['calculation_method'] ?? null));
        $data['centre_aggregation'] ??= $data['reporting_scope'] === 'programme'
            ? 'NON-AGGREGATABLE'
            : array_key_first($this->acrossProjectOptions($dataType, $data['calculation_method'] ?? null));

        if (isset($data['options']) && is_array($data['options'])) {
            $data['options'] = array_values(array_unique(array_filter(array_map('trim', $data['options']))));
        }

        $data['target_tracking'] = in_array($dataType, app(TargetService::class)->supportedDataTypes(), true);
        if ($data['target_tracking'] && in_array($dataType, ['boolean', 'single_select', 'status'], true)) {
            $data['direction'] = 'status';
        }

        if (empty($data['allowed_extensions'])) {
            $data['allowed_extensions'] = config('impact.evidence_extensions');
        }

        $errors = [];
        if ($data['target_tracking'] && in_array($dataType, ['integer', 'decimal', 'percentage', 'ratio', 'calculated'], true) && ! in_array($data['direction'] ?? null, ['higher', 'lower', 'range'], true)) {
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
        $acrossProjectOptions = $data['reporting_scope'] === 'programme'
            ? ['NON-AGGREGATABLE' => 'Not applicable']
            : $this->acrossProjectOptions($dataType, $data['calculation_method'] ?? null);
        if (array_key_exists('centre_aggregation', $data) && ! array_key_exists((string) $data['centre_aggregation'], $acrossProjectOptions)) {
            $errors['centre_aggregation'] = 'Choose an across-project method supported by this input type.';
        }
        $timeAggregationOptions = $this->timeAggregationOptions($dataType, $data['calculation_method'] ?? null);
        if (! array_key_exists((string) ($data['time_aggregation'] ?? ''), $timeAggregationOptions)) {
            $errors['time_aggregation'] = 'Choose an over-time method supported by this input type.';
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
