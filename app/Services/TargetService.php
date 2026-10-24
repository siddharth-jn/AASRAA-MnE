<?php

namespace App\Services;

use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class TargetService
{
    /** @var array<int, string> */
    private const NUMERIC_DATA_TYPES = ['integer', 'decimal', 'percentage', 'ratio', 'calculated'];

    /** @var array<int, string> */
    private const STATUS_DATA_TYPES = ['boolean', 'single_select', 'status'];

    public function __construct(private readonly IndicatorReportingPeriodService $indicatorPeriods) {}

    /** @return array<int, string> */
    public function supportedDataTypes(): array
    {
        return [...self::NUMERIC_DATA_TYPES, ...self::STATUS_DATA_TYPES];
    }

    public function typeForIndicator(IndicatorDefinition $indicator): ?string
    {
        if (in_array($indicator->data_type, self::NUMERIC_DATA_TYPES, true)) {
            return $indicator->direction === 'range' ? 'range' : 'numeric';
        }

        if (in_array($indicator->data_type, self::STATUS_DATA_TYPES, true)) {
            return 'status';
        }

        return null;
    }

    /** @return array<string, string> */
    public function statusOptionsForIndicator(IndicatorDefinition $indicator): array
    {
        $options = $indicator->data_type === 'boolean'
            ? ['Yes', 'No']
            : ($indicator->options ?? []);

        return collect($options)
            ->mapWithKeys(fn (string $option): array => [$option => $option])
            ->all();
    }

    public function stepForIndicator(IndicatorDefinition $indicator): float|int
    {
        return $indicator->data_type === 'integer' ? 1 : 10 ** -((int) ($indicator->precision ?? 2));
    }

    public function minimumForIndicator(IndicatorDefinition $indicator): ?float
    {
        if ($indicator->min_value !== null) {
            return (float) $indicator->min_value;
        }

        return $indicator->data_type === 'percentage' ? 0 : null;
    }

    public function maximumForIndicator(IndicatorDefinition $indicator): ?float
    {
        if ($indicator->max_value !== null) {
            return (float) $indicator->max_value;
        }

        return $indicator->data_type === 'percentage' ? 100 : null;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): IndicatorTarget
    {
        $period = CarbonImmutable::parse($data['period'])->startOfDay();
        [$periodStart] = $this->indicatorPeriods->boundaries($period, (string) $data['period_type']);
        $target = IndicatorTarget::query()
            ->where('indicator_assignment_id', $data['indicator_assignment_id'])
            ->whereDate('period', $periodStart)
            ->where('period_type', $data['period_type'])
            ->first();
        $prepared = $this->prepareAndValidate($data, $target);

        if ($target) {
            $target->update($prepared);

            return $target->fresh();
        }

        return IndicatorTarget::create($prepared);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function prepareAndValidate(array $data, ?IndicatorTarget $target = null): array
    {
        $assignment = IndicatorAssignment::query()->with('indicator')->findOrFail($data['indicator_assignment_id']);
        $period = CarbonImmutable::parse($data['period'])->startOfDay();
        [$periodStart, $periodEnd] = $this->indicatorPeriods->boundaries($period, (string) $data['period_type']);
        $data['period'] = $periodStart->toDateString();
        $data['period_end'] = $periodEnd->toDateString();
        $data['is_demo'] = (bool) $assignment->is_demo;

        $targetType = $this->typeForIndicator($assignment->indicator);
        $errors = [];
        if ($data['period_type'] !== $assignment->indicator->frequency) {
            $errors['period_type'] = 'The target period must match this indicator’s reporting frequency.';
        }
        if (! $assignment->indicator->target_tracking) {
            $errors['indicator_assignment_id'] = 'Enable target tracking for this indicator before setting a target.';
        }
        if ($targetType === null) {
            $errors['indicator_assignment_id'] = 'Targets are not supported for this indicator input type.';
        }
        if ($targetType === 'numeric' && ($error = $this->numericValueError($data['value'] ?? null, $assignment->indicator))) {
            $errors['value'] = $error;
        }
        if ($targetType === 'range') {
            $minimumError = $this->numericValueError($data['minimum_value'] ?? null, $assignment->indicator);
            $maximumError = $this->numericValueError($data['maximum_value'] ?? null, $assignment->indicator);
            if ($minimumError) {
                $errors['minimum_value'] = $minimumError;
            }
            if ($maximumError) {
                $errors['maximum_value'] = $maximumError;
            }
            if (! $minimumError && ! $maximumError && (float) $data['minimum_value'] > (float) $data['maximum_value']) {
                $errors['maximum_value'] = 'The maximum target must be greater than or equal to the minimum target.';
            }
        }
        if ($targetType === 'status') {
            $status = (string) ($data['status_value'] ?? '');
            if (blank($status)) {
                $errors['status_value'] = 'Choose an expected status.';
            } elseif (! array_key_exists($status, $this->statusOptionsForIndicator($assignment->indicator))) {
                $errors['status_value'] = 'Choose one of the accepted values for this indicator.';
            }
        }
        if ($targetType !== null) {
            $data['target_type'] = $targetType;
        }

        $duplicate = IndicatorTarget::query()
            ->where('indicator_assignment_id', $data['indicator_assignment_id'])
            ->whereDate('period', $data['period'])
            ->where('period_type', $data['period_type'])
            ->when($target, fn ($query) => $query->whereKeyNot($target->getKey()))
            ->exists();
        if ($duplicate) {
            $errors['period'] = 'A target already exists for this indicator, project, and reporting period.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($targetType !== 'numeric') {
            $data['value'] = null;
        }
        if ($targetType !== 'range') {
            $data['minimum_value'] = null;
            $data['maximum_value'] = null;
        }
        if ($targetType !== 'status') {
            $data['status_value'] = null;
        }

        return $data;
    }

    private function numericValueError(mixed $value, IndicatorDefinition $indicator): ?string
    {
        if (! is_numeric($value)) {
            return 'Enter a numeric target value.';
        }

        $numericValue = (float) $value;
        if ($indicator->data_type === 'integer' && floor($numericValue) !== $numericValue) {
            return 'This indicator requires a whole-number target.';
        }

        $minimum = $this->minimumForIndicator($indicator);
        $maximum = $this->maximumForIndicator($indicator);
        if ($minimum !== null && $numericValue < (float) $minimum) {
            return "The target must be at least {$minimum}.";
        }
        if ($maximum !== null && $numericValue > (float) $maximum) {
            return "The target may not exceed {$maximum}.";
        }

        if ($indicator->data_type !== 'integer' && $indicator->precision !== null) {
            $normalized = rtrim(rtrim(sprintf('%.14F', $numericValue), '0'), '.');
            $decimalPlaces = str_contains($normalized, '.') ? strlen(substr(strrchr($normalized, '.'), 1)) : 0;
            if ($decimalPlaces > (int) $indicator->precision) {
                return "The target may have at most {$indicator->precision} decimal places.";
            }
        }

        return null;
    }

    public function findForPeriod(int $assignmentId, CarbonInterface $period): ?IndicatorTarget
    {
        $assignment = IndicatorAssignment::query()->with('indicator')->findOrFail($assignmentId);
        if (! $assignment->indicator->target_tracking) {
            return null;
        }

        return IndicatorTarget::query()
            ->where('indicator_assignment_id', $assignmentId)
            ->where('period_type', $assignment->indicator->frequency)
            ->where('active', true)
            ->whereDate('period', '<=', $period)
            ->whereDate('period_end', '>=', $period)
            ->first();
    }
}
