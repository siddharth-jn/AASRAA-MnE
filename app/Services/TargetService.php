<?php

namespace App\Services;

use App\Models\IndicatorTarget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class TargetService
{
    /** @param array<string, mixed> $data */
    public function save(array $data): IndicatorTarget
    {
        $period = CarbonImmutable::parse($data['period'])->startOfDay();
        [$periodStart] = $this->boundaries($period, (string) $data['period_type']);
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
        $period = CarbonImmutable::parse($data['period'])->startOfDay();
        [$periodStart, $periodEnd] = $this->boundaries($period, (string) $data['period_type']);
        $data['period'] = $periodStart->toDateString();
        $data['period_end'] = $periodEnd->toDateString();

        $targetType = (string) ($data['target_type'] ?? 'numeric');
        $errors = [];
        if ($targetType === 'numeric' && ! is_numeric($data['value'] ?? null)) {
            $errors['value'] = 'Enter a numeric target value.';
        }
        if ($targetType === 'range') {
            if (! is_numeric($data['minimum_value'] ?? null) || ! is_numeric($data['maximum_value'] ?? null)) {
                $errors['minimum_value'] = 'Enter both ends of the target range.';
            } elseif ((float) $data['minimum_value'] > (float) $data['maximum_value']) {
                $errors['maximum_value'] = 'The maximum target must be greater than or equal to the minimum target.';
            }
        }
        if ($targetType === 'status' && blank($data['status_value'] ?? null)) {
            $errors['status_value'] = 'Choose or enter the expected status.';
        }

        $duplicate = IndicatorTarget::query()
            ->where('indicator_assignment_id', $data['indicator_assignment_id'])
            ->whereDate('period', $data['period'])
            ->where('period_type', $data['period_type'])
            ->when($target, fn ($query) => $query->whereKeyNot($target->getKey()))
            ->exists();
        if ($duplicate) {
            $errors['period'] = 'A target already exists for this indicator, centre, and reporting period.';
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

    public function findForPeriod(int $assignmentId, CarbonInterface $period): ?IndicatorTarget
    {
        return IndicatorTarget::query()
            ->where('indicator_assignment_id', $assignmentId)
            ->where('active', true)
            ->whereDate('period', '<=', $period)
            ->whereDate('period_end', '>=', $period)
            ->orderByRaw("CASE period_type WHEN 'monthly' THEN 1 WHEN 'quarterly' THEN 2 WHEN 'half_yearly' THEN 3 ELSE 4 END")
            ->first();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function boundaries(CarbonImmutable $period, string $periodType): array
    {
        return match ($periodType) {
            'monthly' => [$period->startOfMonth(), $period->endOfMonth()],
            'quarterly' => $this->fiscalBoundaries($period, 3),
            'half_yearly' => $this->fiscalBoundaries($period, 6),
            'annual' => $this->fiscalBoundaries($period, 12),
            default => throw ValidationException::withMessages(['period_type' => 'Choose a valid target period.']),
        };
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function fiscalBoundaries(CarbonImmutable $period, int $months): array
    {
        $offsetFromApril = ($period->month - 4 + 12) % 12;
        $periodOffset = $offsetFromApril % $months;
        $start = $period->startOfMonth()->subMonths($periodOffset);

        return [$start, $start->addMonths($months)->subDay()->endOfDay()];
    }
}
