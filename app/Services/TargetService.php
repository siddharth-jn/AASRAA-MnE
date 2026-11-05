<?php

namespace App\Services;

use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TargetService
{
    /** @var array<int, string> */
    private const NUMERIC_DATA_TYPES = ['integer', 'decimal', 'percentage', 'ratio', 'calculated'];

    /** @var array<int, string> */
    private const STATUS_DATA_TYPES = ['boolean', 'single_select', 'status'];

    public function __construct(
        private readonly IndicatorReportingPeriodService $indicatorPeriods,
        private readonly TargetChangeService $targetChanges,
    ) {}

    /** @return array<int, string> */
    public function supportedDataTypes(): array
    {
        return [...self::NUMERIC_DATA_TYPES, ...self::STATUS_DATA_TYPES];
    }

    public function typeForIndicator(IndicatorDefinition $indicator): ?string
    {
        if (in_array($indicator->data_type, ['ratio', 'calculated'], true)
            || ($indicator->data_type === 'percentage' && $indicator->calculation_method === 'ratio_percentage')) {
            return 'ratio';
        }

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
    public function save(array $data, string $source = 'manual'): IndicatorTarget
    {
        $period = CarbonImmutable::parse($data['period'])->startOfDay();
        [$periodStart] = $this->indicatorPeriods->boundaries($period, 'monthly');
        $target = IndicatorTarget::query()
            ->where('indicator_assignment_id', $data['indicator_assignment_id'])
            ->whereDate('period', $periodStart)
            ->where('period_type', 'monthly')
            ->first();
        $prepared = $this->prepareAndValidate($data, $target);
        $previous = $target ? $this->snapshot($target) : null;

        return DB::transaction(function () use ($target, $prepared, $previous, $source): IndicatorTarget {
            if ($target) {
                $target->update($prepared);
                $target = $target->fresh();
            } else {
                $target = IndicatorTarget::create($prepared);
            }

            $current = $this->snapshot($target);
            if ($previous === $current) {
                return $target;
            }

            $this->recordRevision($target, $previous === null ? 'created' : ($previous['active'] ? 'updated' : 'restored'), $source);
            $this->targetChanges->handle($target, false);

            return $target;
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function prepareAndValidate(array $data, ?IndicatorTarget $target = null): array
    {
        $assignment = IndicatorAssignment::query()->with('indicator')->findOrFail($data['indicator_assignment_id']);
        $period = CarbonImmutable::parse($data['period'])->startOfDay();
        [$periodStart, $periodEnd] = $this->indicatorPeriods->boundaries($period, 'monthly');
        $data['period'] = $periodStart->toDateString();
        $data['period_end'] = $periodEnd->toDateString();
        $data['period_type'] = 'monthly';
        $data['is_demo'] = (bool) $assignment->is_demo;

        $targetType = $this->typeForIndicator($assignment->indicator);
        $errors = [];
        if (! $assignment->indicator->isEffectiveForPeriod($periodStart)) {
            $errors['period'] = 'Targets cannot be set before reporting starts in '.$assignment->indicator->effective_date->format('F Y').'.';
        }
        if ($assignment->indicator->frequency !== 'monthly') {
            $errors['period_type'] = 'All indicators must use monthly reporting before targets can be set.';
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
        if ($targetType === 'ratio') {
            $numeratorError = $this->numericValueError($data['target_numerator'] ?? null, $assignment->indicator, false);
            $denominatorError = $this->numericValueError($data['target_denominator'] ?? null, $assignment->indicator, false);
            if ($numeratorError) {
                $errors['target_numerator'] = $numeratorError;
            }
            if ($denominatorError || (float) ($data['target_denominator'] ?? 0) <= 0) {
                $errors['target_denominator'] = 'Enter a target denominator greater than zero.';
            }
            if (! isset($errors['target_numerator']) && ! isset($errors['target_denominator'])) {
                $data['value'] = (float) $data['target_numerator'] / (float) $data['target_denominator'] * 100;
                if (($error = $this->numericValueError($data['value'], $assignment->indicator))) {
                    $errors['target_numerator'] = $error;
                }
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

        if (! in_array($targetType, ['numeric', 'ratio'], true)) {
            $data['value'] = null;
        }
        if ($targetType !== 'range') {
            $data['minimum_value'] = null;
            $data['maximum_value'] = null;
        }
        if ($targetType !== 'status') {
            $data['status_value'] = null;
        }
        if ($targetType !== 'ratio') {
            $data['target_numerator'] = null;
            $data['target_denominator'] = null;
        }
        $data['active'] = true;

        return $data;
    }

    private function numericValueError(mixed $value, IndicatorDefinition $indicator, bool $enforceIndicatorBounds = true): ?string
    {
        if (! is_numeric($value)) {
            return 'Enter a numeric target value.';
        }

        $numericValue = (float) $value;
        if ($indicator->data_type === 'integer' && floor($numericValue) !== $numericValue) {
            return 'This indicator requires a whole-number target.';
        }

        $minimum = $enforceIndicatorBounds ? $this->minimumForIndicator($indicator) : 0;
        $maximum = $enforceIndicatorBounds ? $this->maximumForIndicator($indicator) : null;
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

    public function clear(int $assignmentId, CarbonInterface|string $period, string $source = 'manual'): ?IndicatorTarget
    {
        $periodStart = CarbonImmutable::parse($period)->startOfMonth();
        $target = IndicatorTarget::query()
            ->where('indicator_assignment_id', $assignmentId)
            ->where('period_type', 'monthly')
            ->whereDate('period', $periodStart)
            ->first();

        if (! $target || ! $target->active) {
            return $target;
        }

        return DB::transaction(function () use ($target, $source): IndicatorTarget {
            $target->update(['active' => false]);
            $target = $target->fresh();
            $this->recordRevision($target, 'removed', $source);
            $this->targetChanges->handle($target, true);

            return $target;
        });
    }

    /** @return array<string, bool|float|string|null> */
    public function snapshot(IndicatorTarget $target): array
    {
        return [
            'target_type' => $target->target_type,
            'value' => $target->value === null ? null : (float) $target->value,
            'minimum_value' => $target->minimum_value === null ? null : (float) $target->minimum_value,
            'maximum_value' => $target->maximum_value === null ? null : (float) $target->maximum_value,
            'target_numerator' => $target->target_numerator === null ? null : (float) $target->target_numerator,
            'target_denominator' => $target->target_denominator === null ? null : (float) $target->target_denominator,
            'status_value' => $target->status_value,
            'notes' => $target->notes,
            'active' => (bool) $target->active,
        ];
    }

    public function display(?IndicatorTarget $target): string
    {
        if (! $target || ! $target->active) {
            return 'N/A';
        }

        return match ($target->target_type) {
            'range' => $this->formatNumber((float) $target->minimum_value).'–'.$this->formatNumber((float) $target->maximum_value),
            'ratio' => $this->formatNumber((float) $target->target_numerator).'/'.$this->formatNumber((float) $target->target_denominator),
            'status' => (string) $target->status_value,
            default => $this->formatNumber((float) $target->value),
        };
    }

    private function recordRevision(IndicatorTarget $target, string $action, string $source): void
    {
        $target->revisions()->create([
            'user_id' => Auth::id(),
            'version' => ($target->revisions()->max('version') ?? 0) + 1,
            'action' => $action,
            'source' => $source,
            ...$this->snapshot($target),
            'is_demo' => (bool) $target->is_demo,
        ]);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    public function findForPeriod(int $assignmentId, CarbonInterface $period): ?IndicatorTarget
    {
        $assignment = IndicatorAssignment::query()->with('indicator')->findOrFail($assignmentId);
        if (! $assignment->indicator->target_tracking) {
            return null;
        }

        return IndicatorTarget::query()
            ->where('indicator_assignment_id', $assignmentId)
            ->where('period_type', 'monthly')
            ->where('active', true)
            ->whereDate('period', CarbonImmutable::instance($period)->startOfMonth())
            ->first();
    }
}
